<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

use LLM\Skills\Config\ProjectConfig;

/**
 * Internal result of {@see ProjectConfigMapper::mapSkillsBlock()}: the
 * mapped {@see ProjectConfig} plus a flag recording whether the block
 * declared its donor sources under the deprecated `remote` key rather
 * than `sources`. The mapper is shared by the external `skills.json`
 * and inline `extra.skills` paths; bundling the flag lets both surface
 * the deprecation upward without widening the public `fromExtra()`
 * signature.
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
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public ProjectConfig $config,
        public bool $usedDeprecatedSourcesKey,
    ) {}
}
