<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

/**
 * Output of {@see SkillEnumerator::enumerate()}.
 *
 * `skills` lists every skill found across the input donors. `warnings`
 * carries non-fatal diagnostics — the most common one being "source
 * directory does not exist", which we recover from by skipping that donor.
 *
 * @psalm-immutable
 */
final readonly class SkillEnumerationResult
{
    /**
     * @param list<Skill>  $skills
     * @param list<string> $warnings
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public array $skills,
        public array $warnings,
    ) {}
}
