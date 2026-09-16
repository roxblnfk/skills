<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use LLM\Skills\Discovery\InstalledSkill;
use LLM\Skills\Discovery\Skill;

/**
 * Decides which installed skills `--clean` wipes before the copy phase.
 *
 * Two shapes, picked by whether the run is scoped:
 *
 * - **Unscoped** (no positional `<package>`, no `--from`) — every installed
 *   skill goes. That includes directories no donor claims any more, which is
 *   the only way an orphan left behind by a removed donor ever leaves the
 *   target.
 * - **Scoped** — only installed skills whose name the current run is about to
 *   write back. A scoped run has no picture of the donors it filtered out, so
 *   wiping beyond what it can restore would delete another donor's skills and
 *   leave the user to re-run without the filter to get them back.
 *
 * Pairing in the scoped case is by directory name, matching the rule in
 * {@see InstalledSkill}. Nothing on disk records which donor a directory came
 * from, so name equality is the only available link.
 *
 * @psalm-immutable
 */
final readonly class PurgePlanner
{
    /**
     * @param list<InstalledSkill> $installed what currently sits in the target
     * @param list<Skill> $incoming skills the approved donors are about to write
     * @param bool $scoped whether a package filter or `--from` narrowed this run
     *
     * @return list<InstalledSkill>
     *
     * @psalm-pure
     */
    public function plan(array $installed, array $incoming, bool $scoped): array
    {
        if (!$scoped) {
            return $installed;
        }

        $incomingNames = [];
        foreach ($incoming as $skill) {
            $incomingNames[$skill->name] = true;
        }

        return \array_values(\array_filter(
            $installed,
            static fn(InstalledSkill $s): bool => isset($incomingNames[$s->name]),
        ));
    }
}
