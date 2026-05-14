<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

use LLM\Skills\Discovery\Skill;

/**
 * One row under an approved donor in the `skills:show` listing.
 *
 * Combines the donor-side skill candidate with two pieces of state
 * derived from the project:
 *
 * - {@see $status} answers "would the next `skills:update` change this
 *   skill's bytes on disk?"
 * - {@see $conflictWith} is non-null when **another trusted donor**
 *   declares a skill with the same directory name. The conflict aborts
 *   sync, so the formatter shows it inline next to both contenders.
 *
 * @psalm-immutable
 */
final readonly class SkillInspection
{
    /**
     * @param Skill $skill donor-side skill candidate
     * @param SyncStatus $status what `update` would do to this skill
     * @param non-empty-string|null $conflictWith Composer name of the other contender,
     *        or `null` if there is no conflict
     * @param string|null $description short one-line description extracted from the donor-side `SKILL.md`
     *        frontmatter (`description:` key). `null` when the file has no frontmatter or no `description` key.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public Skill $skill,
        public SyncStatus $status,
        public ?string $conflictWith = null,
        public ?string $description = null,
    ) {}
}
