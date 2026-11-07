<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use Internal\Path;
use LLM\Skills\Discovery\InstalledSkill;
use LLM\Skills\Discovery\Skill;
use LLM\Skills\Filesystem\PathContainment;

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
 * from, so the name is the only available link. A name spelled in another case
 * pairs only when the file system resolves it to the same directory: on a
 * case-insensitive target the copy lands there, while on a case-sensitive one
 * that directory belongs to a different skill and must survive.
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
     * @psalm-suppress ImpureMethodCall a differently cased name is paired by asking the file system
     */
    public function plan(array $installed, array $incoming, bool $scoped): array
    {
        if (!$scoped) {
            return $installed;
        }

        /** @var array<string, list<non-empty-string>> $incomingByKey */
        $incomingByKey = [];
        foreach ($incoming as $skill) {
            $incomingByKey[SkillConflict::collisionKey($skill->name)][] = $skill->name;
        }

        $purge = [];
        foreach ($installed as $s) {
            foreach ($incomingByKey[SkillConflict::collisionKey($s->name)] ?? [] as $name) {
                if ($name === $s->name || PathContainment::isSame($s->dir->parent()->join($name), $s->dir)) {
                    $purge[] = $s;
                    break;
                }
            }
        }

        return $purge;
    }

    /**
     * Split a purge list into what may be deleted and what must stay because it
     * is or holds the source of an incoming skill, such as a `dir` source pointing
     * into the target: deleting it would remove that source before it is read.
     *
     * Only a source inside the target can sit in an installed skill, so the
     * sources are narrowed to those once and most runs check no pair at all.
     *
     * @param list<InstalledSkill> $purge
     * @param list<Skill> $incoming
     *
     * @return array{list<InstalledSkill>, list<InstalledSkill>} deletable first, kept second
     *
     * @psalm-suppress ImpureMethodCall containment is a question for the file system
     */
    public function partitionSourceHolders(array $purge, array $incoming, Path $target): array
    {
        if ($purge === []) {
            return [[], []];
        }

        $sources = [];
        foreach ($incoming as $skill) {
            if (PathContainment::isWithin($skill->sourceDir, $target)) {
                $sources[] = $skill->sourceDir;
            }
        }
        if ($sources === []) {
            return [$purge, []];
        }

        $deletable = [];
        $kept = [];
        foreach ($purge as $installed) {
            foreach ($sources as $source) {
                if (PathContainment::isWithin($source, $installed->dir)) {
                    $kept[] = $installed;
                    continue 2;
                }
            }
            $deletable[] = $installed;
        }

        return [$deletable, $kept];
    }
}
