<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Config\ProjectConfigResolution;
use LLM\Skills\Config\RemoteEntry;
use LLM\Skills\Config\TrustedVendors;
use LLM\Skills\Config\VendorPattern;
use LLM\Skills\Discovery\Provider\ProviderId;

/**
 * Maps the consumer project's configuration into a typed
 * {@see ProjectConfig}.
 *
 * Two sources feed this mapper:
 *
 * - **`skills.json`** at the project root, when present. Strict mapping,
 *   loaded via {@see ExternalProjectConfigLoader}; this is the modern
 *   surface the project owns explicitly.
 * - **`extra.skills`** inside `composer.json`. Legacy / fallback
 *   behaviour; lenient (Composer's own `extra` is a free-form area,
 *   so unknown keys are silently ignored).
 *
 * The two are mutually exclusive — see {@see self::forProject()}. When
 * both are present, `skills.json` wins and the inline project keys are
 * collected into {@see ProjectConfigResolution::$ignoredInlineKeys} so
 * the caller can surface a `-v` warning.
 *
 * A broken root config is **fatal**: the user owns this file, so loud
 * failure is preferable to silent defaults.
 *
 * Not annotated `@psalm-immutable` because {@see self::forProject()}
 * reads the filesystem via {@see ExternalProjectConfigLoader}. The
 * other methods stay `@psalm-mutation-free` / `@psalm-pure` and are
 * safe to call from inside pure contexts.
 */
final readonly class ProjectConfigMapper
{
    /**
     * Project-level keys understood under `extra.skills`. Donor-side
     * keys (`source`) are deliberately excluded — they describe the
     * package as a donor, not as a consumer, and must not be flagged
     * as "shadowed" when `skills.json` is in effect.
     *
     * @var list<non-empty-string>
     */
    public const PROJECT_KEYS = [
        'target',
        'aliases',
        'trusted',
        'trusted-replace',
        'discovery',
        'auto-sync',
        'external-target',
        'local',
        'remote',
    ];

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private ExternalProjectConfigLoader $externalLoader = new ExternalProjectConfigLoader(),
    ) {}

    /**
     * Resolve the project config, choosing between `skills.json` (when
     * it exists at `$projectRoot`) and the inline `extra.skills` block.
     *
     * The returned {@see ProjectConfigResolution} bundles the config
     * with the list of inline project keys that were shadowed by
     * `skills.json` (empty unless `skills.json` won and the inline
     * block also carried project keys). Callers emit a `-v` warning
     * from that list — no IO happens here.
     *
     * @param mixed $extra raw value of the root package's `extra` field
     *
     * @throws MalformedProjectConfig when either source is malformed
     */
    public function forProject(Path $projectRoot, mixed $extra): ProjectConfigResolution
    {
        $external = $this->externalLoader->load($projectRoot);
        if ($external !== null) {
            $config = $this->mapSkillsBlock($external, 'skills.json');
            $ignored = $this->collectIgnoredInlineKeys($extra);

            return new ProjectConfigResolution($config, $ignored);
        }

        return new ProjectConfigResolution($this->fromExtra($extra));
    }

    /**
     * Legacy entry point: map an inline `extra` array directly. Kept
     * for the auto-sync hook (which has no convenient project-root
     * handle) and for unit tests that exercise the inline branch in
     * isolation.
     *
     * @param mixed $extra raw value of root `composer.json` `extra` field
     *
     * @throws MalformedProjectConfig when `extra.skills` is present but invalid
     *
     * @psalm-mutation-free
     */
    public function fromExtra(mixed $extra): ProjectConfig
    {
        if ($extra === null || $extra === []) {
            return ProjectConfig::default();
        }

        if (!\is_array($extra)) {
            throw new MalformedProjectConfig('Root extra must be an object');
        }

        $skills = $extra['skills'] ?? null;
        if ($skills === null) {
            return ProjectConfig::default();
        }
        if (!\is_array($skills)) {
            throw new MalformedProjectConfig('extra.skills must be an object');
        }

        return $this->mapSkillsBlock($skills, 'extra.skills');
    }

    /**
     * Cheap normalisation purely for same-string detection at the config
     * level: forward-slash separators and no trailing slash. Not a path
     * resolver — that's `SyncPlanner`'s job.
     *
     * @psalm-pure
     */
    private static function lexicalNormalise(string $path): string
    {
        return \rtrim(\str_replace('\\', '/', $path), '/');
    }

    /**
     * Render a per-field path for error messages: `extra.skills.target`
     * for the inline source, `skills.json: target` for the external one
     * (the latter uses a colon to keep the file name distinct from a
     * dotted JSON pointer).
     *
     * @param non-empty-string $prefix
     * @param non-empty-string $name
     *
     * @return non-empty-string
     *
     * @psalm-pure
     */
    private static function field(string $prefix, string $name): string
    {
        return $prefix === 'skills.json'
            ? "skills.json: {$name}"
            : "{$prefix}.{$name}";
    }

    /**
     * Read an optional string field that must be non-empty when present.
     * Returns null when the key is absent.
     *
     * @param non-empty-string $field path prefix for error messages, e.g. `skills.json: remote[0]`
     * @param non-empty-string $name field name inside the entry
     *
     * @return non-empty-string|null
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-pure
     */
    private static function optionalNonEmptyString(mixed $value, string $field, string $name): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!\is_string($value) || $value === '') {
            throw new MalformedProjectConfig(
                $field . '.' . $name . ' must be a non-empty string',
            );
        }
        return $value;
    }

    /**
     * Pick adapter-specific extras out of a `remote[]` entry — anything
     * that is not one of the well-known keys (`from`, `package`, `url`,
     * `host`, `ref`). Stored verbatim so adapters can read their own
     * keys (`sha256` on `zip`, custom proxy options on `go`, …) without
     * a mapper-level schema for every adapter.
     *
     * @param array<array-key, mixed> $entry
     *
     * @return array<string, mixed>
     *
     * @psalm-pure
     */
    private static function collectExtras(array $entry): array
    {
        /** @var array<string, mixed> $extras */
        $extras = [];
        /** @var mixed $value */
        foreach ($entry as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            if (
                $key === 'from'
                || $key === 'package'
                || $key === 'url'
                || $key === 'host'
                || $key === 'ref'
                || $key === 'skills'
            ) {
                continue;
            }
            /** @psalm-suppress MixedAssignment intentional — adapter-specific extras are stored verbatim */
            $extras[$key] = $value;
        }

        return $extras;
    }

    /**
     * Parse the optional `remote[].skills` allowlist. Three states:
     *
     * - absent / `null` → no filter, every skill is synced (default);
     * - non-empty list → only the listed skills are synced;
     * - empty list (`[]`) → the donor is registered but no skills are
     *   pulled from it (useful for staging or temporary opt-out
     *   without deleting the entry).
     *
     * Non-list values, or lists with non-string / empty-string
     * elements, are load-time errors.
     *
     * @return list<non-empty-string>|null
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-pure
     */
    private static function mapRemoteSkills(mixed $raw, string $field): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!\is_array($raw) || !\array_is_list($raw)) {
            throw new MalformedProjectConfig(
                $field . '.skills must be a list of skill names',
            );
        }
        /** @var list<non-empty-string> $out */
        $out = [];
        /** @var mixed $name */
        foreach ($raw as $i => $name) {
            if (!\is_string($name) || $name === '') {
                throw new MalformedProjectConfig(\sprintf(
                    '%s.skills[%d] must be a non-empty string',
                    $field,
                    $i,
                ));
            }
            $out[] = $name;
        }
        return $out;
    }

    /**
     * Per-field validator shared by both inline and external sources.
     * `$prefix` is woven into error messages so the user knows where
     * to look (`extra.skills.target` vs `skills.json: target`).
     *
     * @param array<array-key, mixed> $skills
     * @param non-empty-string $prefix human-readable origin of the block, e.g. `extra.skills` or `skills.json`
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-mutation-free
     */
    private function mapSkillsBlock(array $skills, string $prefix): ProjectConfig
    {
        $target = $skills['target'] ?? ProjectConfig::DEFAULT_TARGET;
        if (!\is_string($target) || $target === '') {
            throw new MalformedProjectConfig(
                self::field($prefix, 'target') . ' must be a non-empty string',
            );
        }

        $aliases = $this->mapAliases($skills['aliases'] ?? [], $target, $prefix);

        $trusted = $this->mapTrusted($skills['trusted'] ?? [], $prefix);

        $replace = $skills['trusted-replace'] ?? false;
        if (!\is_bool($replace)) {
            throw new MalformedProjectConfig(
                self::field($prefix, 'trusted-replace') . ' must be a boolean',
            );
        }

        $discovery = $skills['discovery'] ?? false;
        if (!\is_bool($discovery)) {
            throw new MalformedProjectConfig(
                self::field($prefix, 'discovery') . ' must be a boolean',
            );
        }

        $autoSync = $skills['auto-sync'] ?? true;
        if (!\is_bool($autoSync)) {
            throw new MalformedProjectConfig(
                self::field($prefix, 'auto-sync') . ' must be a boolean',
            );
        }

        $externalTarget = $skills['external-target'] ?? false;
        if (!\is_bool($externalTarget)) {
            throw new MalformedProjectConfig(
                self::field($prefix, 'external-target') . ' must be a boolean',
            );
        }

        $local = $this->mapLocal($skills['local'] ?? [], $prefix);
        $remote = $this->mapRemote($skills['remote'] ?? [], $prefix);

        return new ProjectConfig(
            target: $target,
            trusted: $trusted,
            trustedReplace: $replace,
            discovery: $discovery,
            aliases: $aliases,
            autoSync: $autoSync,
            externalTarget: $externalTarget,
            local: $local,
            remote: $remote,
        );
    }

    /**
     * Parse and validate the `local` block. Each key must be a known
     * local-provider id ({@see ProviderId::LOCAL_IDS}); each value must
     * be a boolean. The result is a sparse map — unspecified ids fall
     * back to {@see ProjectConfig::isLocalEnabled()}'s per-provider
     * default.
     *
     * @param non-empty-string $prefix
     *
     * @return array<non-empty-string, bool>
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-pure
     */
    private function mapLocal(mixed $raw, string $prefix): array
    {
        if ($raw === [] || $raw === null) {
            return [];
        }
        if (!\is_array($raw) || \array_is_list($raw)) {
            throw new MalformedProjectConfig(
                self::field($prefix, 'local') . ' must be a map of provider-id to boolean',
            );
        }

        $out = [];
        /** @var mixed $value */
        foreach ($raw as $id => $value) {
            if (!\is_string($id) || $id === '') {
                throw new MalformedProjectConfig(
                    self::field($prefix, 'local') . ' keys must be non-empty strings',
                );
            }
            if (!ProviderId::isKnownLocal($id)) {
                throw new MalformedProjectConfig(\sprintf(
                    '%s.%s is not a known local provider (known: %s)',
                    self::field($prefix, 'local'),
                    $id,
                    \implode(', ', ProviderId::LOCAL_IDS),
                ));
            }
            if (!\is_bool($value)) {
                throw new MalformedProjectConfig(\sprintf(
                    '%s.%s must be a boolean',
                    self::field($prefix, 'local'),
                    $id,
                ));
            }
            $out[$id] = $value;
        }

        return $out;
    }

    /**
     * Parse and validate the `remote[]` list. Each entry is structurally
     * an object with a mandatory `from` (adapter id), exactly one of
     * `package` / `url`, and optional `host` / `ref` plus
     * adapter-specific extras. Composite uniqueness on
     * `(from, host, package|url)` is enforced inside this method so the
     * caller does not have to.
     *
     * @param non-empty-string $prefix
     *
     * @return list<RemoteEntry>
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-mutation-free
     */
    private function mapRemote(mixed $raw, string $prefix): array
    {
        if ($raw === [] || $raw === null) {
            return [];
        }
        if (!\is_array($raw) || !\array_is_list($raw)) {
            throw new MalformedProjectConfig(
                self::field($prefix, 'remote') . ' must be a list of objects',
            );
        }

        $out = [];
        $seen = [];
        /**
         * @var int $index
         * @var mixed $entry
         */
        foreach ($raw as $index => $entry) {
            $parsed = $this->mapRemoteEntry($entry, $prefix, $index);

            $key = $parsed->compositeKey();
            if (isset($seen[$key])) {
                throw new MalformedProjectConfig(\sprintf(
                    '%s[%d] duplicates an earlier entry (composite key: %s)',
                    self::field($prefix, 'remote'),
                    $index,
                    $key,
                ));
            }
            $seen[$key] = true;
            $out[] = $parsed;
        }

        return $out;
    }

    /**
     * @param non-empty-string $prefix
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-pure
     */
    private function mapRemoteEntry(mixed $entry, string $prefix, int $index): RemoteEntry
    {
        $field = self::field($prefix, 'remote') . '[' . $index . ']';

        if (!\is_array($entry) || \array_is_list($entry)) {
            throw new MalformedProjectConfig($field . ' must be an object');
        }

        /** @var mixed $rawFrom */
        $rawFrom = $entry['from'] ?? null;
        if (!\is_string($rawFrom) || $rawFrom === '') {
            throw new MalformedProjectConfig(
                $field . '.from must be a non-empty string',
            );
        }
        if (!ProviderId::isKnownRemote($rawFrom)) {
            throw new MalformedProjectConfig(\sprintf(
                '%s.from "%s" is not a known remote adapter (known: %s)',
                $field,
                $rawFrom,
                \implode(', ', ProviderId::REMOTE_IDS),
            ));
        }
        /** @var non-empty-string $from */
        $from = $rawFrom;

        $package = self::optionalNonEmptyString($entry['package'] ?? null, $field, 'package');
        $url = self::optionalNonEmptyString($entry['url'] ?? null, $field, 'url');
        $host = self::optionalNonEmptyString($entry['host'] ?? null, $field, 'host');
        $ref = self::optionalNonEmptyString($entry['ref'] ?? null, $field, 'ref');

        // Identifier rules: URL-only adapters take `url`; name-based
        // adapters take `package`. Exactly one of the two must be set
        // and it must be the one the adapter expects — a typo here
        // (e.g. `package` on a `zip` entry) is a silent footgun if we
        // accept it, so it surfaces as a load-time error instead.
        if (ProviderId::isUrlOnlyRemote($from)) {
            if ($url === null) {
                throw new MalformedProjectConfig(
                    $field . '.url is required for adapter "' . $from . '"',
                );
            }
            if ($package !== null) {
                throw new MalformedProjectConfig(
                    $field . '.package is not allowed for adapter "' . $from . '" (use url)',
                );
            }
        } else {
            if ($package === null) {
                throw new MalformedProjectConfig(
                    $field . '.package is required for adapter "' . $from . '"',
                );
            }
            if ($url !== null) {
                throw new MalformedProjectConfig(
                    $field . '.url is not allowed for adapter "' . $from . '" (use package)',
                );
            }
        }

        $skills = self::mapRemoteSkills($entry['skills'] ?? null, $field);

        $extras = self::collectExtras($entry);

        return new RemoteEntry(
            from: $from,
            package: $package,
            url: $url,
            host: $host,
            ref: $ref,
            extras: $extras,
            skills: $skills,
        );
    }

    /**
     * Validate and return the `aliases` list. Each entry must be a non-empty
     * string. After light lexical normalisation (separator unification,
     * trailing-slash strip) no alias may equal `$target`, and no two aliases
     * may collide.
     *
     * Resolution against the project root happens later in
     * {@see \LLM\Skills\Sync\SyncPlanner}; this method only catches the
     * obvious raw-string mistakes. The planner runs a second pass against
     * fully resolved absolute paths, which catches cases like
     * `./.claude/skills` vs `.claude/skills` after both join the project root.
     *
     * @param non-empty-string $target the already-validated target path; used to forbid
     *         `target == alias` configurations up front
     * @param non-empty-string $prefix
     *
     * @return list<non-empty-string>
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-pure
     */
    private function mapAliases(mixed $raw, string $target, string $prefix): array
    {
        if ($raw === []) {
            return [];
        }
        if (!\is_array($raw) || !\array_is_list($raw)) {
            throw new MalformedProjectConfig(
                self::field($prefix, 'aliases') . ' must be a list of non-empty strings',
            );
        }

        $normalisedTarget = self::lexicalNormalise($target);

        $out = [];
        $seen = [];
        /** @var int $index */
        foreach ($raw as $index => $value) {
            if (!\is_string($value) || $value === '') {
                throw new MalformedProjectConfig(\sprintf(
                    '%s[%d] must be a non-empty string',
                    self::field($prefix, 'aliases'),
                    $index,
                ));
            }

            $normalised = self::lexicalNormalise($value);
            if ($normalised === $normalisedTarget) {
                throw new MalformedProjectConfig(\sprintf(
                    '%s[%d] (%s) cannot equal %s',
                    self::field($prefix, 'aliases'),
                    $index,
                    $value,
                    self::field($prefix, 'target'),
                ));
            }
            if (isset($seen[$normalised])) {
                throw new MalformedProjectConfig(\sprintf(
                    '%s[%d] (%s) duplicates an earlier entry',
                    self::field($prefix, 'aliases'),
                    $index,
                    $value,
                ));
            }
            $seen[$normalised] = true;
            $out[] = $value;
        }

        return $out;
    }

    /**
     * @param non-empty-string $prefix
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-mutation-free
     */
    private function mapTrusted(mixed $raw, string $prefix): TrustedVendors
    {
        if (!\is_array($raw)) {
            throw new MalformedProjectConfig(
                self::field($prefix, 'trusted') . ' must be a list of patterns',
            );
        }

        $patterns = [];
        /** @var int|string $index */
        foreach ($raw as $index => $value) {
            if (!\is_string($value) || $value === '') {
                throw new MalformedProjectConfig(\sprintf(
                    '%s[%s] must be a non-empty string',
                    self::field($prefix, 'trusted'),
                    $index,
                ));
            }

            try {
                $patterns[] = VendorPattern::fromString($value);
            } catch (\InvalidArgumentException $e) {
                throw new MalformedProjectConfig(\sprintf(
                    '%s[%s]: %s',
                    self::field($prefix, 'trusted'),
                    $index,
                    $e->getMessage(),
                ));
            }
        }

        return new TrustedVendors($patterns);
    }

    /**
     * Pick the project-level keys that exist under `extra.skills` so the
     * caller can warn about them being shadowed by `skills.json`.
     * Donor-side keys (`source`) are deliberately not included — they
     * remain meaningful even when `skills.json` drives the project
     * config, because the same package may also be a donor.
     *
     * @return list<non-empty-string>
     *
     * @psalm-pure
     */
    private function collectIgnoredInlineKeys(mixed $extra): array
    {
        if (!\is_array($extra)) {
            return [];
        }
        $skills = $extra['skills'] ?? null;
        if (!\is_array($skills)) {
            return [];
        }

        $present = [];
        foreach (self::PROJECT_KEYS as $key) {
            if (\array_key_exists($key, $skills)) {
                $present[] = $key;
            }
        }

        return $present;
    }
}
