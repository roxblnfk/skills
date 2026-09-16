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
 * `droppedDonors` names the donors that contributed nothing because their
 * source directory was missing or unreadable. It is the typed half of those
 * warnings, for callers that have to act on the gap rather than print it:
 * a dropped donor's skills are absent from `skills`, so anything that
 * deletes before writing must not run.
 *
 * @psalm-immutable
 */
final readonly class SkillEnumerationResult
{
    /**
     * @param list<Skill> $skills
     * @param list<string> $warnings
     * @param list<non-empty-string> $droppedDonors package names, each also described in `warnings`
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public array $skills,
        public array $warnings,
        public array $droppedDonors = [],
    ) {}
}
