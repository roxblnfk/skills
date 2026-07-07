<?php

declare(strict_types=1);

namespace LLM\Skills\Add;

use Internal\Path;
use LLM\Skills\Config\Mapper\ExternalProjectConfigLoader;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Config\Mapper\ProjectConfigMigrator;
use LLM\Skills\Config\RemoteEntry;
use LLM\Skills\Filesystem\AtomicFileWriter;

/**
 * Mutates the project's `skills.json` to insert or update a single
 * `sources[]` entry on behalf of `skills:add`.
 *
 * Three guarantees:
 *
 * - **Upsert by composite key** — `(from, host ?? '', package | url)`.
 *   Same key ⇒ overwrite in place; new key ⇒ append.
 * - **Stable sort** — after the upsert the whole `sources[]` is
 *   reordered by composite key so diffs are deterministic across
 *   adds. Manual edits may produce any order in between; the next
 *   add normalises it.
 * - **Atomic write** — payload goes to a sibling temp file then
 *   atomically renames into place. A failed write cannot leave a
 *   half-written `skills.json`.
 *
 * When the file does not exist yet, the writer creates it with the
 * canonical `$schema` pointer (the same one
 * {@see ProjectConfigMigrator} emits). Existing files keep all their
 * other keys verbatim — only `sources[]` is touched. A file still on
 * the deprecated `remote` key has its entries folded into `sources`
 * and the old key dropped, since the writer rewrites the whole file.
 */
final readonly class SkillsJsonWriter
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private ExternalProjectConfigLoader $loader = new ExternalProjectConfigLoader(),
    ) {}

    /**
     * Insert or update `$entry` in `<projectRoot>/skills.json`.
     *
     * @throws \RuntimeException when the file cannot be written
     */
    public function upsertSource(Path $projectRoot, RemoteEntry $entry): void
    {
        /** @psalm-suppress ImpureMethodCall Path::join() is mutation-free */
        $filePath = (string) $projectRoot->join(ExternalProjectConfigLoader::FILE_NAME);

        /** @var array<string, mixed> $payload */
        $payload = $this->loader->load($projectRoot) ?? [];
        // ExternalProjectConfigLoader strips `$schema` — re-add it on
        // write so editors keep the IDE-validation pointer.
        $payload = ['$schema' => ProjectConfigMigrator::SCHEMA_URL] + $payload;

        $entries = self::normaliseExisting(self::collectExistingEntries($payload));
        $updated = self::upsertByCompositeKey($entries, $entry);
        $sorted = self::stableSort($updated);

        // The whole file is rewritten under the canonical key; a
        // lingering deprecated alias is dropped from the output.
        unset($payload[ProjectConfigMapper::DEPRECATED_SOURCES_KEY]);
        $payload[ProjectConfigMapper::SOURCES_KEY] = \array_map(self::serialise(...), $sorted);

        AtomicFileWriter::write($filePath, self::encode($payload));
    }

    /**
     * Gather the donor entries already on file, reading both the
     * canonical `sources` key and the deprecated `remote` alias so a
     * legacy file's entries survive the rewrite. A hand-edited file
     * carrying both keys (rejected by the mapper on load) has its two
     * lists concatenated; the composite-key upsert collapses any
     * duplicates that result.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<mixed>
     *
     * @psalm-pure
     */
    private static function collectExistingEntries(array $payload): array
    {
        $out = [];
        foreach ([ProjectConfigMapper::SOURCES_KEY, ProjectConfigMapper::DEPRECATED_SOURCES_KEY] as $key) {
            /** @var mixed $list */
            $list = $payload[$key] ?? null;
            if (!\is_array($list)) {
                continue;
            }
            /** @var mixed $entry */
            foreach ($list as $entry) {
                /** @psalm-suppress MixedAssignment entries are normalised downstream */
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Normalise existing `sources[]` content into a list of arrays. We
     * keep the loaded shape verbatim — re-parsing through the mapper
     * would be redundant since the file was already validated when it
     * was written.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return list<array<string, mixed>>
     *
     * @psalm-pure
     */
    private static function normaliseExisting(array $raw): array
    {
        $out = [];
        /** @var mixed $entry */
        foreach ($raw as $entry) {
            if (\is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Replace **every** entry that shares the new entry's composite
     * key with a single normalised copy, or append when no match
     * exists. A pre-existing `skills.json` may have been hand-edited
     * to contain duplicate keys (the mapper rejects this on load, but
     * the writer also operates on raw-loaded payloads); collapsing
     * the duplicates here guarantees the post-upsert file always
     * satisfies the composite-key uniqueness constraint, regardless
     * of what shape the file was in before.
     *
     * @param list<array<string, mixed>> $existing
     *
     * @return list<array<string, mixed>>
     *
     * @psalm-pure
     */
    private static function upsertByCompositeKey(array $existing, RemoteEntry $new): array
    {
        $newKey = $new->compositeKey();

        $written = false;
        $out = [];
        foreach ($existing as $entry) {
            if (self::compositeKeyOf($entry) === $newKey) {
                // First match consumes the replacement; subsequent
                // matches drop out entirely so duplicates collapse
                // into a single entry. The `skills` allowlist is
                // additive across consecutive `skills:add` calls — we
                // merge any pre-existing names with the new ones so a
                // second invocation can grow the allowlist without
                // wiping the first one. Order: pre-existing names
                // first (preserved verbatim), then new names appended,
                // deduplicated.
                if (!$written) {
                    $mergedSkills = self::mergeSkills(self::extractSkills($entry), $new->skills);
                    $out[] = self::serialiseWithSkills($new, $mergedSkills);
                    $written = true;
                }
                continue;
            }
            $out[] = $entry;
        }
        if (!$written) {
            $out[] = self::serialise($new);
        }
        return $out;
    }

    /**
     * Pull the `skills` list out of an already-serialised entry.
     * Three return shapes match the three load-time states:
     *
     * - `null` — the `skills` key is absent altogether (sync every
     *   skill the donor ships);
     * - `[]` — the key is present but empty (donor registered, no
     *   skills pulled from it). Preserved so a follow-up upsert
     *   without `--skill` does not silently lose the explicit empty
     *   allowlist;
     * - non-empty list — the user's allowlist.
     *
     * Defensive against hand-edited files: anything that is not a
     * list or contains a non-string element is treated as "no key
     * present" rather than crashing the writer.
     *
     * @param array<string, mixed> $entry
     *
     * @return list<non-empty-string>|null
     *
     * @psalm-pure
     */
    private static function extractSkills(array $entry): ?array
    {
        if (!\array_key_exists('skills', $entry)) {
            return null;
        }
        /** @var mixed $raw */
        $raw = $entry['skills'];
        if (!\is_array($raw) || !\array_is_list($raw)) {
            return null;
        }
        /** @var list<non-empty-string> $out */
        $out = [];
        /** @var mixed $name */
        foreach ($raw as $name) {
            if (\is_string($name) && $name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Combine an existing `skills` list with the new one, preserving
     * insertion order and dropping duplicates. When neither side has
     * an allowlist the result is `null` (omitted from the file
     * entirely). When exactly one side carries names, those win as-is.
     *
     * @param list<non-empty-string>|null $existing
     * @param list<non-empty-string>|null $incoming
     *
     * @return list<non-empty-string>|null
     *
     * @psalm-pure
     */
    private static function mergeSkills(?array $existing, ?array $incoming): ?array
    {
        if ($existing === null) {
            return $incoming;
        }
        if ($incoming === null) {
            return $existing;
        }
        /** @var list<non-empty-string> $out */
        $out = [];
        $seen = [];
        foreach ([...$existing, ...$incoming] as $name) {
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $out[] = $name;
        }
        return $out;
    }

    /**
     * Render an entry with `skills` overridden by the supplied list
     * (used by the upsert path to honour the merge result without
     * mutating the original {@see RemoteEntry}). `$skills === null`
     * omits the field entirely.
     *
     * @param list<non-empty-string>|null $skills
     *
     * @return array<string, mixed>
     *
     * @psalm-pure
     */
    private static function serialiseWithSkills(RemoteEntry $entry, ?array $skills): array
    {
        $out = self::serialise($entry);
        // serialise() already emitted `skills` from the entry; the
        // upsert-merge path may compute a different list, so we
        // re-insert in the canonical position (after `ref`, before
        // extras) by rebuilding the map.
        unset($out['skills']);
        if ($skills === null) {
            return $out;
        }
        $rebuilt = [];
        $inserted = false;
        /** @var mixed $v */
        foreach ($out as $k => $v) {
            /** @psalm-suppress MixedAssignment serialised entries carry adapter-specific shapes */
            $rebuilt[$k] = $v;
            if (!$inserted && $k === 'ref') {
                $rebuilt['skills'] = $skills;
                $inserted = true;
            }
        }
        if (!$inserted) {
            // No `ref` to anchor to — append `skills` before any
            // adapter-specific extras at the tail, which means in
            // practice "before whatever came after the identifier".
            // Falling back to a plain append keeps the entry valid;
            // the slight reorder vs the documented canonical layout is
            // acceptable for the rare no-`ref` case.
            $rebuilt['skills'] = $skills;
        }
        return $rebuilt;
    }

    /**
     * Sort `sources[]` by composite key so diffs are stable across
     * adds. Two entries that share a composite key would have been
     * collapsed by {@see self::upsertByCompositeKey()}; here we just
     * need a deterministic ordering.
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>>
     *
     * @psalm-pure
     */
    private static function stableSort(array $entries): array
    {
        /** @psalm-suppress MixedArgumentTypeCoercion the array entries are well-typed for compositeKeyOf */
        \usort(
            $entries,
            static fn(array $a, array $b): int =>
                self::compositeKeyOf($a) <=> self::compositeKeyOf($b),
        );
        /** @var list<array<string, mixed>> $entries */
        return $entries;
    }

    /**
     * Render an entry as the JSON-serialisable map for storage. Key
     * order is fixed for stable diffs: `from` → `host` (if present) →
     * `package` or `url` → `ref` (if present) → `skills` (if present)
     * → extras.
     *
     * @param RemoteEntry|array<string, mixed> $entry
     *
     * @return array<string, mixed>
     *
     * @psalm-pure
     */
    private static function serialise(RemoteEntry|array $entry): array
    {
        if ($entry instanceof RemoteEntry) {
            $out = ['from' => $entry->from];
            if ($entry->host !== null) {
                $out['host'] = $entry->host;
            }
            if ($entry->package !== null) {
                $out['package'] = $entry->package;
            }
            if ($entry->url !== null) {
                $out['url'] = $entry->url;
            }
            if ($entry->ref !== null) {
                $out['ref'] = $entry->ref;
            }
            if ($entry->skills !== null) {
                $out['skills'] = $entry->skills;
            }
            /** @var mixed $v */
            foreach ($entry->extras as $k => $v) {
                /** @psalm-suppress MixedAssignment extras carry adapter-specific shapes */
                $out[$k] = $v;
            }
            return $out;
        }
        // Already-serialised array entries (preserving previously
        // stored extras and field order) flow through verbatim.
        return $entry;
    }

    /**
     * Composite key of an already-serialised entry. Mirrors
     * {@see RemoteEntry::compositeKey()} but operates on the raw
     * map form so we never have to round-trip through the mapper.
     *
     * @param array<string, mixed> $entry
     *
     * @psalm-pure
     */
    private static function compositeKeyOf(array $entry): string
    {
        /** @var mixed $from */
        $from = $entry['from'] ?? null;
        /** @var mixed $host */
        $host = $entry['host'] ?? null;
        $fromStr = \is_string($from) ? $from : '';
        $hostStr = \is_string($host) ? $host : '';
        $identifier = '';
        /** @var mixed $package */
        $package = $entry['package'] ?? null;
        if (\is_string($package)) {
            $identifier = $package;
        } else {
            /** @var mixed $url */
            $url = $entry['url'] ?? null;
            if (\is_string($url)) {
                $identifier = $url;
            }
        }
        return $fromStr . '|' . $hostStr . '|' . $identifier;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @psalm-pure
     */
    private static function encode(array $payload): string
    {
        $json = \json_encode(
            $payload,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        if ($json === false) {
            throw new \RuntimeException('failed to encode skills.json payload');
        }
        return $json . "\n";
    }
}
