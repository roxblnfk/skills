<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

/**
 * Result of {@see Mapper\ProjectConfigMapper::forProject()}.
 *
 * Bundles the resolved {@see ProjectConfig} with the list of inline
 * `extra.skills` keys that were shadowed because a `skills.json` file
 * exists at the project root. Callers emit a `-v`-only warning naming
 * those keys so a confused user sees why their inline `target` (or
 * other key) had no effect.
 *
 * Also carries what the personal `skills.local.json` layered over the
 * shared configuration changed, so commands can report it and
 * `skills:show` can mark the values it sourced.
 *
 * @psalm-immutable
 */
final readonly class ProjectConfigResolution
{
    /**
     * @param list<non-empty-string> $ignoredInlineKeys names of project-level keys
     *        present under `extra.skills` in `composer.json` that were shadowed
     *        by a `skills.json` at the project root. Always empty when the
     *        inline block was used as the source of truth.
     * @param bool $usedDeprecatedSourcesKey true when the winning config block declared
     *        its donor sources under the deprecated `remote` key instead of `sources`.
     *        Callers surface a deprecation notice; write-mode callers migrate the file
     *        before mapping, so they never observe it set.
     * @param list<non-empty-string> $usedDeprecatedDependencyKeys legacy dependency keys
     *        (`trusted`, `trusted-replace`, `local`) the winning block used instead of the
     *        `dependencies` key. Empty when the block used `dependencies` or declared no
     *        dependency config. Callers surface a deprecation notice naming these keys;
     *        write-mode callers migrate the file before mapping, so they never observe it set.
     *        Describes the shared base only; layers above it reject the legacy keys.
     * @param list<LayerChanges> $layers one record per config layer applied over the shared
     *        base, in fold order. Empty when no `skills.local.json` exists.
     * @param bool $deprecatedSourcesKeyInline true when `$usedDeprecatedSourcesKey` comes from the
     *        inline `extra.skills` block. An inline `remote` list is never fetched; it takes effect
     *        only once a write-mode run moves it into `sources` in `skills.json`.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public ProjectConfig $config,
        public array $ignoredInlineKeys = [],
        public bool $usedDeprecatedSourcesKey = false,
        public array $usedDeprecatedDependencyKeys = [],
        public array $layers = [],
        public bool $deprecatedSourcesKeyInline = false,
    ) {}
}
