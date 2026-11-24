<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\LayerChanges;
use LLM\Skills\Discovery\Provider\ProviderId;

/**
 * Folds raw project config layers left to right — the shared base
 * first, then every layer over it, such as the personal
 * `skills.local.json` — into one raw config for
 * {@see ProjectConfigMapper} to map.
 *
 * Merging happens on raw arrays, before mapping, so a key a layer
 * leaves out stays unset instead of turning into its default. How each
 * top-level key combines is declared once in {@see self::STRATEGIES}.
 *
 * Layers above the base are expected to be validated already; the
 * merger only shapes values and leaves type errors in malformed input
 * for the mapper to report. The base must use canonical keys only:
 * deprecated aliases are folded before the base is handed over.
 *
 * @psalm-immutable
 */
final class ConfigLayerMerger
{
    /**
     * Merge rule of every top-level key a config file may carry, except
     * the editor-only `$schema`, which the loader strips.
     */
    public const STRATEGIES = [
        'target' => MergeStrategy::Override,
        'path-from-root' => MergeStrategy::Override,
        'discovery' => MergeStrategy::Override,
        'auto-sync' => MergeStrategy::Override,
        'clean' => MergeStrategy::Override,
        'vendor-sources' => MergeStrategy::Override,
        'aliases' => MergeStrategy::Union,
        ProjectConfigMapper::SOURCES_KEY => MergeStrategy::SourcesByIdentity,
        ProjectConfigMapper::DEPENDENCIES_KEY => MergeStrategy::Dependencies,
        ProjectConfigMapper::DEPRECATED_SOURCES_KEY => MergeStrategy::Rejected,
        'trusted' => MergeStrategy::Rejected,
        'trusted-replace' => MergeStrategy::Rejected,
        'local' => MergeStrategy::Rejected,
    ];

    /**
     * Canonical replacement named in the error for a rejected key.
     */
    private const REPLACEMENTS = [
        ProjectConfigMapper::DEPRECATED_SOURCES_KEY => ProjectConfigMapper::SOURCES_KEY,
        'trusted' => ProjectConfigMapper::DEPENDENCIES_KEY,
        'trusted-replace' => ProjectConfigMapper::DEPENDENCIES_KEY,
        'local' => ProjectConfigMapper::DEPENDENCIES_KEY,
    ];

    /**
     * Fold `$layers` left to right. The first layer is the base and is
     * taken verbatim; every following layer is checked with
     * {@see self::assertLayerable()} and merged over the result.
     *
     * @param non-empty-list<ConfigLayer> $layers
     *
     * @throws MalformedProjectConfig when a layer above the base carries a key it may not set
     *
     * @psalm-mutation-free
     */
    public function merge(array $layers): MergedConfig
    {
        $values = $layers[0]->values;
        $changes = [];
        foreach (\array_slice($layers, 1) as $layer) {
            $this->assertLayerable($layer);
            [$values, $changes[]] = self::fold($values, $layer);
        }

        return new MergedConfig($values, $changes);
    }

    /**
     * Reject keys a layer above the base may not set: deprecated
     * aliases, which such a layer has no legacy to carry, and keys
     * without a merge rule. Null is rejected too, at the top level and
     * inside a `dependencies.<id>` object: it would otherwise read as
     * "unset" and erase the shared value instead of changing it.
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-pure
     */
    public function assertLayerable(ConfigLayer $layer): void
    {
        /** @var mixed $value */
        foreach ($layer->values as $key => $value) {
            $strategy = self::STRATEGIES[$key] ?? null;
            if ($strategy === null) {
                throw new MalformedProjectConfig(\sprintf(
                    '%s: key "%s" cannot be layered over the shared configuration',
                    $layer->name,
                    (string) $key,
                ));
            }
            if ($strategy === MergeStrategy::Rejected) {
                throw new MalformedProjectConfig(\sprintf(
                    '%s: deprecated key "%s" is not accepted here; use "%s"',
                    $layer->name,
                    (string) $key,
                    self::REPLACEMENTS[$key] ?? ProjectConfigMapper::DEPENDENCIES_KEY,
                ));
            }

            if ($value === null) {
                throw new MalformedProjectConfig(\sprintf('%s: %s must not be null', $layer->name, (string) $key));
            }
            if ($key === ProjectConfigMapper::DEPENDENCIES_KEY && \is_array($value)) {
                self::assertNoNullDependencyFields($layer->name, $value);
            }
        }
    }

    /**
     * @param non-empty-string $layerName
     * @param array<array-key, mixed> $dependencies
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-pure
     */
    private static function assertNoNullDependencyFields(string $layerName, array $dependencies): void
    {
        /** @var mixed $entry */
        foreach ($dependencies as $id => $entry) {
            $field = ProjectConfigMapper::DEPENDENCIES_KEY . '.' . (string) $id;
            if ($entry === null) {
                throw new MalformedProjectConfig(\sprintf('%s: %s must not be null', $layerName, $field));
            }
            if (!\is_array($entry)) {
                continue;
            }
            /** @var mixed $value */
            foreach ($entry as $name => $value) {
                if ($value === null) {
                    throw new MalformedProjectConfig(\sprintf(
                        '%s: %s.%s must not be null',
                        $layerName,
                        $field,
                        (string) $name,
                    ));
                }
            }
        }
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array{array<array-key, mixed>, LayerChanges}
     *
     * @psalm-pure
     */
    private static function fold(array $values, ConfigLayer $layer): array
    {
        $summary = [];
        $changedKeys = [];
        $addedAliases = [];

        /** @var mixed $upper */
        foreach ($layer->values as $key => $upper) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }
            $hasLower = \array_key_exists($key, $values);
            /** @var mixed $lower */
            $lower = $values[$key] ?? null;

            switch (self::STRATEGIES[$key] ?? MergeStrategy::Override) {
                case MergeStrategy::Union:
                    /** @psalm-suppress MixedAssignment the mapper validates the value */
                    [$merged, $added] = self::union($lower, $upper, paths: $key === 'aliases');
                    if ($added !== []) {
                        $summary[] = \sprintf('%s +%d', $key, \count($added));
                        $changedKeys[] = $key;
                        if ($key === 'aliases') {
                            /** @var mixed $alias */
                            foreach ($added as $alias) {
                                if (\is_string($alias) && $alias !== '') {
                                    $addedAliases[] = $alias;
                                }
                            }
                        }
                    }
                    break;

                case MergeStrategy::SourcesByIdentity:
                    /** @psalm-suppress MixedAssignment the mapper validates the value */
                    [$merged, $added, $replaced] = self::sourcesByIdentity($lower, $upper);
                    $counts = \array_filter([
                        $added > 0 ? '+' . $added : null,
                        $replaced > 0 ? '~' . $replaced : null,
                    ]);
                    if ($counts !== []) {
                        $summary[] = $key . ' ' . \implode(' ', $counts);
                        $changedKeys[] = $key;
                    }
                    break;

                case MergeStrategy::Dependencies:
                    /** @psalm-suppress MixedAssignment the mapper validates the value */
                    [$merged, $items] = self::dependencies($lower, $upper);
                    if ($items !== []) {
                        \array_push($summary, ...$items);
                        $changedKeys[] = $key;
                    }
                    break;

                default:
                    /** @psalm-suppress MixedAssignment the mapper validates the value */
                    $merged = $upper;
                    if (!$hasLower || $lower !== $upper) {
                        $summary[] = $key . '=' . self::render($upper);
                        $changedKeys[] = $key;
                    }
            }

            /** @psalm-suppress MixedAssignment the mapper validates the value */
            $values[$key] = $merged;
        }

        return [$values, new LayerChanges($layer->name, $summary, $changedKeys, $addedAliases)];
    }

    /**
     * Lower items first, then upper items not present yet. A value that
     * is not a list replaces the other side as is, leaving the shape
     * error to the mapper.
     *
     * @param bool $paths compare string items as paths, after
     *        {@see ProjectConfigMapper::layeredPathKey()}, so `./.claude/skills/`
     *        matches `.claude/skills`
     *
     * @return array{mixed, list<mixed>} merged value and the items the upper side added
     *
     * @psalm-pure
     */
    private static function union(mixed $lower, mixed $upper, bool $paths = false): array
    {
        if (!\is_array($upper) || !\array_is_list($upper)) {
            return [$upper, []];
        }
        $merged = \is_array($lower) && \array_is_list($lower) ? $lower : [];

        $seen = [];
        /** @var mixed $item */
        foreach ($merged as $item) {
            $seen[] = self::unionKey($item, $paths);
        }

        $added = [];
        /** @var mixed $item */
        foreach ($upper as $item) {
            $key = self::unionKey($item, $paths);
            if (!\in_array($key, $seen, true)) {
                $seen[] = $key;
                /** @psalm-suppress MixedAssignment */
                $merged[] = $item;
                /** @psalm-suppress MixedAssignment */
                $added[] = $item;
            }
        }

        return [$merged, $added];
    }

    /**
     * Comparison key of a list item. Items that are not strings are
     * malformed and only need to stay distinct from every string.
     *
     * @psalm-pure
     */
    private static function unionKey(mixed $item, bool $paths): string
    {
        if (!\is_string($item)) {
            return "\0" . (string) \json_encode($item);
        }

        return $paths ? ProjectConfigMapper::layeredPathKey($item) : $item;
    }

    /**
     * Union of two `sources[]` lists keyed by source identity: an upper
     * entry sharing the identity of a lower one takes its slot, the
     * rest are appended in order.
     *
     * @return array{mixed, int, int} merged list, appended count, replaced count
     *
     * @psalm-pure
     */
    private static function sourcesByIdentity(mixed $lower, mixed $upper): array
    {
        if (!\is_array($upper) || !\array_is_list($upper)) {
            return [$upper, 0, 0];
        }
        $merged = \is_array($lower) && \array_is_list($lower) ? $lower : [];

        $slots = [];
        /** @var mixed $entry */
        foreach ($merged as $index => $entry) {
            if (\is_array($entry)) {
                $slots[self::sourceKey($entry)] ??= $index;
            }
        }

        $added = 0;
        $replaced = 0;
        /** @var mixed $entry */
        foreach ($upper as $entry) {
            $identity = \is_array($entry) ? self::sourceKey($entry) : null;
            if ($identity !== null && isset($slots[$identity])) {
                if ($merged[$slots[$identity]] !== $entry) {
                    ++$replaced;
                }
                /** @psalm-suppress MixedAssignment */
                $merged[$slots[$identity]] = $entry;
                continue;
            }
            /** @psalm-suppress MixedAssignment */
            $merged[] = $entry;
            if ($identity !== null) {
                $slots[$identity] = \count($merged) - 1;
            }
            ++$added;
        }

        return [$merged, $added, $replaced];
    }

    /**
     * Identity of a raw `sources[]` entry across layers: its composite key
     * ({@see SourceEntryMapper::compositeKeyOf()}), except that the path
     * of a path-only entry (`dir`) is compared via
     * {@see ProjectConfigMapper::layeredPathKey()}, so `./skills` in one
     * file and `skills` in another name the same directory.
     *
     * @param array<array-key, mixed> $entry
     *
     * @psalm-pure
     */
    private static function sourceKey(array $entry): string
    {
        /** @var mixed $from */
        $from = $entry['from'] ?? null;
        /** @var mixed $path */
        $path = $entry['path'] ?? null;
        if (\is_string($from) && ProviderId::isPathOnlySource($from) && \is_string($path)) {
            $entry['path'] = ProjectConfigMapper::layeredPathKey($path);
        }

        return SourceEntryMapper::compositeKeyOf($entry);
    }

    /**
     * Merge two `dependencies` maps entry by entry. For every manager
     * the upper side names, `enabled` and `trusted-replace` override and
     * `trusted` is a union; a bool entry on either side stands for
     * `{ "enabled": <bool> }`, so an upper bool sets `enabled` only.
     *
     * @return array{mixed, list<non-empty-string>} merged map and the change summary items
     *
     * @psalm-pure
     */
    private static function dependencies(mixed $lower, mixed $upper): array
    {
        // An empty JSON object decodes to an empty array, which names no
        // manager and therefore changes nothing.
        if ($upper === []) {
            return [$lower, []];
        }
        if (!\is_array($upper) || \array_is_list($upper)) {
            return [$upper, []];
        }
        $merged = \is_array($lower) && !\array_is_list($lower) ? $lower : [];

        $items = [];
        /** @var mixed $upperEntry */
        foreach ($upper as $id => $upperEntry) {
            $upperEntry = self::dependencyObject($upperEntry);
            $lowerEntry = self::dependencyObject($merged[$id] ?? []);
            if ($upperEntry === null || $lowerEntry === null) {
                /** @psalm-suppress MixedAssignment the mapper reports the shape error */
                $merged[$id] = $upperEntry ?? $upper[$id];
                continue;
            }

            /** @var mixed $value */
            foreach ($upperEntry as $field => $value) {
                $field = (string) $field;
                if ($field === 'trusted') {
                    /** @psalm-suppress MixedAssignment the mapper validates the value */
                    [$union, $added] = self::union($lowerEntry['trusted'] ?? [], $value);
                    /** @psalm-suppress MixedAssignment */
                    $lowerEntry['trusted'] = $union;
                    if ($added !== []) {
                        $items[] = \sprintf('dependencies.%s.trusted +%d', (string) $id, \count($added));
                    }
                    continue;
                }
                if (!\array_key_exists($field, $lowerEntry) || $lowerEntry[$field] !== $value) {
                    $items[] = \sprintf('dependencies.%s.%s=%s', (string) $id, $field, self::render($value));
                }
                /** @psalm-suppress MixedAssignment the mapper validates the value */
                $lowerEntry[$field] = $value;
            }

            $merged[$id] = $lowerEntry;
        }

        return [$merged, $items];
    }

    /**
     * Object form of one `dependencies` entry, or `null` when the value
     * is neither a bool nor an object.
     *
     * @return array<array-key, mixed>|null
     *
     * @psalm-pure
     */
    private static function dependencyObject(mixed $entry): ?array
    {
        if (\is_bool($entry)) {
            return ['enabled' => $entry];
        }
        if ($entry === []) {
            return [];
        }

        return \is_array($entry) && !\array_is_list($entry) ? $entry : null;
    }

    /**
     * Compact rendering of an overridden value for the change summary.
     *
     * @psalm-pure
     */
    private static function render(mixed $value): string
    {
        return match (true) {
            \is_bool($value) => $value ? 'true' : 'false',
            \is_string($value) => $value,
            default => (string) \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        };
    }
}
