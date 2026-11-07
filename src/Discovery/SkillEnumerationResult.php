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
 * source directory was missing or unreadable, every skill they ship was
 * refused for its name, or their allowlist names none of their skills. It is the typed half of those messages, for callers
 * that have to act on the gap rather than print it:
 * a dropped donor's skills are absent from `skills`, so anything that
 * deletes before writing must not run.
 *
 * `skipped` explains each skill a donor ships that is never installed because
 * its directory name is unsafe or unusable, and `renamed` each root skill
 * installed under another name than its frontmatter declares. Unlike
 * `warnings` both are meant for the default output: the donor is otherwise
 * valid, so nothing else would tell the user why a skill is missing or where
 * it went.
 *
 * @psalm-immutable
 */
final readonly class SkillEnumerationResult
{
    /**
     * @param list<Skill> $skills
     * @param list<string> $warnings
     * @param list<non-empty-string> $droppedDonors package names, each also described in `warnings`
     *        (missing or unreadable source, an allowlist matching nothing) or in `skipped`
     *        (every skill refused for its name)
     * @param list<SkillNotice> $skipped one per skill refused for its directory name
     * @param list<SkillNotice> $renamed one per root skill installed under another name
     * @param array<non-empty-string, non-empty-list<string>> $dropReasons why each dropped donor
     *        contributed nothing, keyed by package name, worded as in `warnings` without the
     *        package prefix; a donor whose skills were all refused for their names is absent,
     *        `skipped` explains it
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public array $skills,
        public array $warnings,
        public array $droppedDonors = [],
        public array $skipped = [],
        public array $renamed = [],
        public array $dropReasons = [],
    ) {}
}
