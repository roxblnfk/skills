<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

/**
 * Result of {@see \LLM\Skills\Config\Mapper\ProjectConfigMapper::forProject()}.
 *
 * Bundles the resolved {@see ProjectConfig} with the list of inline
 * `extra.skills` keys that were shadowed because a `skills.json` file
 * exists at the project root. Callers emit a `-v`-only warning naming
 * those keys so a confused user sees why their inline `target` (or
 * other key) had no effect.
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
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public ProjectConfig $config,
        public array $ignoredInlineKeys = [],
    ) {}
}
