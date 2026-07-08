<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

use LLM\Skills\Config\ProjectConfig;

/**
 * Internal result of {@see ProjectConfigMapper::mapSkillsBlock()}: the
 * mapped {@see ProjectConfig} plus flags recording which deprecated
 * aliases the block used — the `remote` sources key, and the legacy
 * `trusted` / `trusted-replace` / `local` keys folded into the
 * `dependencies` block. The mapper is shared by the external
 * `skills.json` and inline `extra.skills` paths; bundling the flags lets
 * both surface the deprecation upward without widening the public
 * `fromExtra()` signature.
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class MappedSkillsBlock
{
    /**
     * @param bool $usedDeprecatedSourcesKey true when the block used `remote`
     *        instead of `sources`
     * @param list<non-empty-string> $usedDeprecatedDependencyKeys legacy dependency keys
     *        (`trusted`, `trusted-replace`, `local`) the block used instead of
     *        `dependencies`, in the order they are listed; empty when the block used
     *        the `dependencies` key or declared no dependency config at all
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public ProjectConfig $config,
        public bool $usedDeprecatedSourcesKey,
        public array $usedDeprecatedDependencyKeys = [],
    ) {}
}
