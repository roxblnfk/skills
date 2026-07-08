<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

use Internal\Path;
use LLM\Skills\Config\DependencyConfig;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Config\ProjectConfigResolution;
use LLM\Skills\Config\SourceEntry;
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
     * Canonical key for the list of explicit donor sources.
     */
    public const SOURCES_KEY = 'sources';

    /**
     * Deprecated alias of {@see self::SOURCES_KEY}, still read everywhere
     * project config is read and auto-migrated by write-mode commands.
     */
    public const DEPRECATED_SOURCES_KEY = 'remote';

    /**
     * Canonical key for per-package-manager dependency config (donor
     * scanning toggles and scoped trust).
     */
    public const DEPENDENCIES_KEY = 'dependencies';

    /**
     * Legacy keys folded into {@see self::DEPENDENCIES_KEY}, still read
     * everywhere project config is read and auto-migrated by write-mode
     * commands. Any of these present alongside `dependencies` is fatal.
     *
     * @var list<non-empty-string>
     */
    public const DEPRECATED_DEPENDENCY_KEYS = [
        'trusted',
        'trusted-replace',
        'local',
    ];

    /**
     * Project-level keys understood under `extra.skills`. Donor-side
     * keys (`source`) are deliberately excluded — they describe the
     * package as a donor, not as a consumer, and must not be flagged
     * as "shadowed" when `skills.json` is in effect.
     *
     * The deprecated `remote` alias and the legacy dependency keys stay
     * in the list so a shadowed inline block still reports them and
     * inline migration can extract them.
     *
     * @var list<non-empty-string>
     */
    public const PROJECT_KEYS = [
        'target',
        'aliases',
        self::DEPENDENCIES_KEY,
        'trusted',
        'trusted-replace',
        'discovery',
        'auto-sync',
        'path-from-root',
        'local',
        self::SOURCES_KEY,
        self::DEPRECATED_SOURCES_KEY,
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
            $mapped = $this->mapSkillsBlock($external, 'skills.json');
            $ignored = $this->collectIgnoredInlineKeys($extra);

            return new ProjectConfigResolution(
                $mapped->config,
                $ignored,
                $mapped->usedDeprecatedSourcesKey,
                $mapped->usedDeprecatedDependencyKeys,
            );
        }

        $mapped = $this->mapExtra($extra);

        return new ProjectConfigResolution(
            $mapped->config,
            usedDeprecatedSourcesKey: $mapped->usedDeprecatedSourcesKey,
            usedDeprecatedDependencyKeys: $mapped->usedDeprecatedDependencyKeys,
        );
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
        return $this->mapExtra($extra)->config;
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
     * @param non-empty-string $field path prefix for error messages, e.g. `skills.json: sources[0]`
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
     * Pick adapter-specific extras out of a `sources[]` entry — anything
     * that is not one of the well-known keys (`from`, `package`, `url`,
     * `host`, `ref`, `skills`, `path`). Stored verbatim so adapters can
     * read their own keys (`sha256` on `zip`, custom proxy options on
     * `go`, …) without a mapper-level schema for every adapter.
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
                || $key === 'path'
            ) {
                continue;
            }
            /** @psalm-suppress MixedAssignment intentional — adapter-specific extras are stored verbatim */
            $extras[$key] = $value;
        }

        return $extras;
    }

    /**
     * Parse the optional `sources[].skills` allowlist. Three states:
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
    private static function mapSourceSkills(mixed $raw, string $field): ?array
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
     * Map an inline `extra` array, keeping the deprecation flag that
     * {@see self::fromExtra()} discards for back-compat. Shared by
     * {@see self::forProject()}'s inline branch.
     *
     * @param mixed $extra raw value of root `composer.json` `extra` field
     *
     * @throws MalformedProjectConfig when `extra.skills` is present but invalid
     *
     * @psalm-mutation-free
     */
    private function mapExtra(mixed $extra): MappedSkillsBlock
    {
        if ($extra === null || $extra === []) {
            return new MappedSkillsBlock(ProjectConfig::default(), false);
        }

        if (!\is_array($extra)) {
            throw new MalformedProjectConfig('Root extra must be an object');
        }

        $skills = $extra['skills'] ?? null;
        if ($skills === null) {
            return new MappedSkillsBlock(ProjectConfig::default(), false);
        }
        if (!\is_array($skills)) {
            throw new MalformedProjectConfig('extra.skills must be an object');
        }

        return $this->mapSkillsBlock($skills, 'extra.skills');
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
    private function mapSkillsBlock(array $skills, string $prefix): MappedSkillsBlock
    {
        $target = $skills['target'] ?? ProjectConfig::DEFAULT_TARGET;
        if (!\is_string($target) || $target === '') {
            throw new MalformedProjectConfig(
                self::field($prefix, 'target') . ' must be a non-empty string',
            );
        }

        $aliases = $this->mapAliases($skills['aliases'] ?? [], $target, $prefix);

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

        $pathFromRoot = $this->mapPathFromRoot($skills['path-from-root'] ?? null, $prefix);

        // `dependencies` is canonical; `trusted`, `trusted-replace` and
        // `local` are the legacy aliases folded into it. Either form is
        // accepted, but mixing them in one block is fatal — the file is
        // ours and strict, so there is no merge or precedence rule.
        $usedDeprecatedDependencyKeys = $this->presentLegacyDependencyKeys($skills);
        if (\array_key_exists(self::DEPENDENCIES_KEY, $skills)) {
            if ($usedDeprecatedDependencyKeys !== []) {
                throw new MalformedProjectConfig(\sprintf(
                    '%s: both "%s" and legacy "%s" are present; keep "%s" only',
                    $prefix,
                    self::DEPENDENCIES_KEY,
                    $usedDeprecatedDependencyKeys[0],
                    self::DEPENDENCIES_KEY,
                ));
            }

            $dependencies = $this->mapDependencies($skills[self::DEPENDENCIES_KEY], $prefix);
            [$trusted, $replace, $managerEnabled] = $this->foldDependencies($dependencies);
            $usedDeprecatedDependencyKeys = [];
        } else {
            $trusted = $this->mapTrusted($skills['trusted'] ?? [], $prefix);
            $replace = $this->mapTrustedReplace($skills['trusted-replace'] ?? false, $prefix);
            $managerEnabled = $this->mapLocal($skills['local'] ?? [], $prefix);
            $dependencies = [];
        }

        // `sources` is canonical; `remote` is the deprecated alias feeding
        // the same validator. Both keys in one block is fatal — the file
        // is ours and strict, so there is no merge or precedence rule.
        $hasSources = \array_key_exists(self::SOURCES_KEY, $skills);
        $usedDeprecatedSourcesKey = \array_key_exists(self::DEPRECATED_SOURCES_KEY, $skills);
        if ($hasSources && $usedDeprecatedSourcesKey) {
            throw new MalformedProjectConfig(\sprintf(
                '%s: both "%s" and "%s" are present; keep "%s" only',
                $prefix,
                self::SOURCES_KEY,
                self::DEPRECATED_SOURCES_KEY,
                self::SOURCES_KEY,
            ));
        }
        $sourcesKey = $usedDeprecatedSourcesKey ? self::DEPRECATED_SOURCES_KEY : self::SOURCES_KEY;
        $sources = $this->mapSources($skills[$sourcesKey] ?? [], $prefix, $sourcesKey);

        $config = new ProjectConfig(
            target: $target,
            trusted: $trusted,
            trustedReplace: $replace,
            discovery: $discovery,
            aliases: $aliases,
            autoSync: $autoSync,
            pathFromRoot: $pathFromRoot,
            managerEnabled: $managerEnabled,
            sources: $sources,
            dependencies: $dependencies,
        );

        return new MappedSkillsBlock($config, $usedDeprecatedSourcesKey, $usedDeprecatedDependencyKeys);
    }

    /**
     * Validate the optional `path-from-root` value: the project's own
     * location relative to the intended containment root, e.g.
     * `packages/api`. It must be a relative path made of plain segments —
     * absolute paths and `.` / `..` parts are rejected, because the value
     * describes a fixed descent from a real ancestor that the planner
     * verifies against the actual project location, not a path to walk.
     *
     * @param non-empty-string $prefix
     *
     * @return non-empty-string|null
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-pure
     */
    private function mapPathFromRoot(mixed $raw, string $prefix): ?string
    {
        if ($raw === null) {
            return null;
        }

        $field = self::field($prefix, 'path-from-root');
        if (!\is_string($raw) || $raw === '') {
            throw new MalformedProjectConfig($field . ' must be a non-empty string');
        }

        // Reject absolute values — path-from-root is a descent below an
        // ancestor, not a root. Checked lexically (leading separator, or a
        // Windows drive-letter prefix); a pure validator cannot use the
        // Internal\Path value object.
        if (
            $raw[0] === '/'
            || $raw[0] === '\\'
            || (\strlen($raw) >= 2 && \ctype_alpha($raw[0]) && $raw[1] === ':')
        ) {
            throw new MalformedProjectConfig(
                $field . ' must be a relative path (the project location below the root), not absolute',
            );
        }

        foreach (\preg_split('#[/\\\\]#', $raw) ?: [] as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new MalformedProjectConfig(
                    $field . ' must contain only plain path segments (no empty, "." or ".." parts)',
                );
            }
        }

        return $raw;
    }

    /**
     * Names of the legacy dependency keys (`trusted`, `trusted-replace`,
     * `local`) present in the block, in canonical order. Used both to
     * detect illegal mixing with `dependencies` and to record which
     * aliases the block relied on for the deprecation notice.
     *
     * @param array<array-key, mixed> $skills
     *
     * @return list<non-empty-string>
     *
     * @psalm-pure
     */
    private function presentLegacyDependencyKeys(array $skills): array
    {
        $present = [];
        foreach (self::DEPRECATED_DEPENDENCY_KEYS as $key) {
            if (\array_key_exists($key, $skills)) {
                $present[] = $key;
            }
        }

        return $present;
    }

    /**
     * Validate the `trusted-replace` toggle. Shared by the legacy flat
     * form and, indirectly, the per-manager object form.
     *
     * @param non-empty-string $prefix
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-pure
     */
    private function mapTrustedReplace(mixed $raw, string $prefix): bool
    {
        if (!\is_bool($raw)) {
            throw new MalformedProjectConfig(
                self::field($prefix, 'trusted-replace') . ' must be a boolean',
            );
        }

        return $raw;
    }

    /**
     * Parse the `dependencies` block: a map of package-manager id to a
     * bool toggle or a per-manager object. Each key must be a known
     * package manager ({@see ProviderId::MANAGER_IDS}).
     *
     * @param non-empty-string $prefix
     *
     * @return array<non-empty-string, DependencyConfig>
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-mutation-free
     */
    private function mapDependencies(mixed $raw, string $prefix): array
    {
        if ($raw === [] || $raw === null) {
            return [];
        }
        if (!\is_array($raw) || \array_is_list($raw)) {
            throw new MalformedProjectConfig(
                self::field($prefix, self::DEPENDENCIES_KEY)
                . ' must be a map of package-manager id to boolean or object',
            );
        }

        $out = [];
        /** @var mixed $value */
        foreach ($raw as $id => $value) {
            if (!\is_string($id) || $id === '') {
                throw new MalformedProjectConfig(
                    self::field($prefix, self::DEPENDENCIES_KEY) . ' keys must be non-empty strings',
                );
            }
            if (!ProviderId::isKnownManager($id)) {
                throw new MalformedProjectConfig(\sprintf(
                    '%s.%s is not a known package manager (known: %s)',
                    self::field($prefix, self::DEPENDENCIES_KEY),
                    $id,
                    \implode(', ', ProviderId::MANAGER_IDS),
                ));
            }
            $out[$id] = $this->mapDependencyEntry($value, $id, $prefix);
        }

        return $out;
    }

    /**
     * Parse one `dependencies` entry. `true` / `false` are shorthand for
     * `{ "enabled": <bool> }`; an object carries `enabled`, `trusted` and
     * `trusted-replace`, all optional. Unknown object fields are fatal —
     * the structure is ours and strict.
     *
     * @param non-empty-string $id already-validated package-manager id
     * @param non-empty-string $prefix
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-mutation-free
     */
    private function mapDependencyEntry(mixed $raw, string $id, string $prefix): DependencyConfig
    {
        $field = self::field($prefix, self::DEPENDENCIES_KEY) . '.' . $id;

        if (\is_bool($raw)) {
            return new DependencyConfig(enabled: $raw, trusted: [], trustedReplace: false);
        }

        if (!\is_array($raw) || \array_is_list($raw)) {
            throw new MalformedProjectConfig($field . ' must be a boolean or an object');
        }

        foreach ($raw as $key => $_value) {
            if ($key !== 'enabled' && $key !== 'trusted' && $key !== 'trusted-replace') {
                throw new MalformedProjectConfig(\sprintf(
                    '%s has unknown field "%s"; allowed fields: enabled, trusted, trusted-replace',
                    $field,
                    (string) $key,
                ));
            }
        }

        // `enabled` absent stays null so the caller can fall back to the
        // per-manager default — configuring `trusted` never enables a
        // manager implicitly.
        $enabled = $raw['enabled'] ?? null;
        if ($enabled !== null && !\is_bool($enabled)) {
            throw new MalformedProjectConfig($field . '.enabled must be a boolean');
        }

        $replace = $raw['trusted-replace'] ?? false;
        if (!\is_bool($replace)) {
            throw new MalformedProjectConfig($field . '.trusted-replace must be a boolean');
        }

        $trusted = $this->mapDependencyTrusted($raw['trusted'] ?? [], $id, $field);

        return new DependencyConfig(enabled: $enabled, trusted: $trusted, trustedReplace: $replace);
    }

    /**
     * Validate a per-manager `trusted` list. Composer patterns go through
     * the {@see VendorPattern} grammar exactly like the flat `trusted`
     * list; npm/go patterns validate structurally only (non-empty
     * strings) — their grammars reject npm/go names by design and are
     * enforced when the providers land. Duplicates are fatal for every
     * manager. Patterns are stored raw regardless of manager.
     *
     * @param non-empty-string $id already-validated package-manager id
     * @param non-empty-string $field error-message prefix, e.g. `skills.json: dependencies.npm`
     *
     * @return list<non-empty-string>
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-mutation-free
     */
    private function mapDependencyTrusted(mixed $raw, string $id, string $field): array
    {
        if (!\is_array($raw) || !\array_is_list($raw)) {
            throw new MalformedProjectConfig($field . '.trusted must be a list of patterns');
        }

        $isComposer = $id === ProviderId::COMPOSER;
        $out = [];
        $seen = [];
        /** @var int $index */
        foreach ($raw as $index => $value) {
            if (!\is_string($value) || $value === '') {
                throw new MalformedProjectConfig(\sprintf(
                    '%s.trusted[%d] must be a non-empty string',
                    $field,
                    $index,
                ));
            }

            if ($isComposer) {
                try {
                    VendorPattern::fromString($value);
                } catch (\InvalidArgumentException $e) {
                    throw new MalformedProjectConfig(\sprintf(
                        '%s.trusted[%d]: %s',
                        $field,
                        $index,
                        $e->getMessage(),
                    ));
                }
            }

            if (isset($seen[$value])) {
                throw new MalformedProjectConfig(\sprintf(
                    '%s.trusted[%d] (%s) duplicates an earlier entry',
                    $field,
                    $index,
                    $value,
                ));
            }
            $seen[$value] = true;
            $out[] = $value;
        }

        return $out;
    }

    /**
     * Fold the parsed per-manager block into the flat runtime fields
     * {@see ProjectConfig} still exposes: every manager's resolved
     * `enabled` populates the `managerEnabled` toggle map, and the
     * `composer` entry (the only manager with a live provider) drives
     * `trusted` and `trustedReplace`.
     *
     * @param array<non-empty-string, DependencyConfig> $dependencies
     *
     * @return array{TrustedVendors, bool, array<non-empty-string, bool>}
     *
     * @psalm-mutation-free
     */
    private function foldDependencies(array $dependencies): array
    {
        $managerEnabled = [];
        foreach ($dependencies as $id => $config) {
            $managerEnabled[$id] = $config->isEnabled($id);
        }

        $composer = $dependencies[ProviderId::COMPOSER] ?? null;
        $trusted = $composer?->trustedVendors() ?? TrustedVendors::empty();
        $replace = $composer?->trustedReplace ?? false;

        return [$trusted, $replace, $managerEnabled];
    }

    /**
     * Parse and validate the `local` block. Each key must be a known
     * package-manager id ({@see ProviderId::MANAGER_IDS}); each value
     * must be a boolean. The result is a sparse map — unspecified ids
     * fall back to {@see ProjectConfig::isManagerEnabled()}'s per-manager
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
            if (!ProviderId::isKnownManager($id)) {
                throw new MalformedProjectConfig(\sprintf(
                    '%s.%s is not a known package manager (known: %s)',
                    self::field($prefix, 'local'),
                    $id,
                    \implode(', ', ProviderId::MANAGER_IDS),
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
     * Parse and validate the `sources[]` list. Each entry is structurally
     * an object with a mandatory `from` (adapter id), an identifier
     * matching the adapter kind (`path` for dir, `url` for URL-only,
     * `package` otherwise), and optional `host` / `ref` plus
     * adapter-specific extras. Composite uniqueness on
     * `(from, host, path|package|url)` is enforced inside this method so
     * the caller does not have to.
     *
     * @param non-empty-string $prefix
     * @param non-empty-string $key config key the list was read from (`sources` or its
     *        deprecated `remote` alias), woven into error messages so the user sees the
     *        key they actually wrote
     *
     * @return list<SourceEntry>
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-mutation-free
     */
    private function mapSources(mixed $raw, string $prefix, string $key): array
    {
        if ($raw === [] || $raw === null) {
            return [];
        }
        if (!\is_array($raw) || !\array_is_list($raw)) {
            throw new MalformedProjectConfig(
                self::field($prefix, $key) . ' must be a list of objects',
            );
        }

        $out = [];
        $seen = [];
        /**
         * @var int $index
         * @var mixed $entry
         */
        foreach ($raw as $index => $entry) {
            $parsed = $this->mapSourceEntry($entry, $prefix, $key, $index);

            $compositeKey = $parsed->compositeKey();
            if (isset($seen[$compositeKey])) {
                throw new MalformedProjectConfig(\sprintf(
                    '%s[%d] duplicates an earlier entry (composite key: %s)',
                    self::field($prefix, $key),
                    $index,
                    $compositeKey,
                ));
            }
            $seen[$compositeKey] = true;
            $out[] = $parsed;
        }

        return $out;
    }

    /**
     * @param non-empty-string $prefix
     * @param non-empty-string $key config key the entry was read from (`sources` or its
     *        deprecated `remote` alias)
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-pure
     */
    private function mapSourceEntry(mixed $entry, string $prefix, string $key, int $index): SourceEntry
    {
        $field = self::field($prefix, $key) . '[' . $index . ']';

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
        if (!ProviderId::isKnownSource($rawFrom)) {
            throw new MalformedProjectConfig(\sprintf(
                '%s.from "%s" is not a known source adapter (known: %s)',
                $field,
                $rawFrom,
                \implode(', ', ProviderId::SOURCE_IDS),
            ));
        }
        /** @var non-empty-string $from */
        $from = $rawFrom;

        $package = self::optionalNonEmptyString($entry['package'] ?? null, $field, 'package');
        $url = self::optionalNonEmptyString($entry['url'] ?? null, $field, 'url');
        $host = self::optionalNonEmptyString($entry['host'] ?? null, $field, 'host');
        $ref = self::optionalNonEmptyString($entry['ref'] ?? null, $field, 'ref');
        $path = self::optionalNonEmptyString($entry['path'] ?? null, $field, 'path');

        // Identifier rules by adapter kind: path-only adapters (dir)
        // take `path`; URL-only adapters take `url`; name-based adapters
        // take `package`. The identifier must be the one the adapter
        // expects — a typo (e.g. `package` on a `zip` entry, or `url`
        // on a `dir` entry) is a silent footgun if we accept it, so it
        // surfaces as a load-time error instead.
        if (ProviderId::isPathOnlySource($from)) {
            // `path` is the identifier; `package` stays optional as a
            // donor-name override; `url`/`host`/`ref` are meaningless.
            if ($path === null) {
                throw new MalformedProjectConfig(
                    $field . '.path is required for adapter "' . $from . '"',
                );
            }
            if ($url !== null) {
                throw new MalformedProjectConfig(
                    $field . '.url is not allowed for adapter "' . $from . '" (use path)',
                );
            }
            if ($host !== null) {
                throw new MalformedProjectConfig(
                    $field . '.host is not allowed for adapter "' . $from . '"',
                );
            }
            if ($ref !== null) {
                throw new MalformedProjectConfig(
                    $field . '.ref is not allowed for adapter "' . $from . '"',
                );
            }
        } else {
            if ($path !== null) {
                throw new MalformedProjectConfig(
                    $field . '.path is not allowed for adapter "' . $from . '" (dir only)',
                );
            }
            if (ProviderId::isUrlOnlySource($from)) {
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
        }

        $skills = self::mapSourceSkills($entry['skills'] ?? null, $field);

        $extras = self::collectExtras($entry);

        return new SourceEntry(
            from: $from,
            package: $package,
            url: $url,
            host: $host,
            ref: $ref,
            extras: $extras,
            skills: $skills,
            path: $path,
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
