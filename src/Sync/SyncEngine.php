<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use Internal\Path;
use LLM\Skills\Discovery\Skill;
use LLM\Skills\Filesystem\LinkGuard;

/**
 * Writes a curated list of skills into a target directory.
 *
 * Discovery is no longer the engine's job — callers pass already
 * enumerated {@see Skill}s (typically from
 * {@see \LLM\Skills\Discovery\SkillEnumerator}). The engine is two
 * phases:
 *
 *   1. Validate — detect skill-name collisions. Reported as
 *      {@see SkillConflict}s; sync aborts before any write.
 *   2. Copy     — recursive, non-destructive merge per skill.
 *
 * Both Composer/Trust resolution and filesystem enumeration happen
 * **before** the engine. The engine is pure write logic plus conflict
 * detection — no knowledge of Composer, IO, or interactive prompts.
 */
final readonly class SyncEngine
{
    /**
     * Directory depth {@see self::copyTree()} descends into a single skill
     * tree before giving up. Real skills are shallow, so this generous cap
     * never bites legitimate content; it is a defence-in-depth backstop so
     * an undetected reparse-point cycle terminates deterministically rather
     * than recursing until the stack or the path length gives out.
     */
    private const MAX_COPY_DEPTH = 32;

    /**
     * @param list<Skill> $skills the skills to write (post-trust, post-enumeration)
     * @param Path $target absolute destination directory; created if missing
     * @param bool $dryRun when `true`, do everything except writing files — conflict detection still runs,
     *        and the returned report's `copied` list still names the skills that *would* have been written.
     */
    public function sync(array $skills, Path $target, bool $dryRun = false): SyncReport
    {
        $conflicts = $this->detectConflicts($skills);
        if ($conflicts !== []) {
            return new SyncReport(copied: [], conflicts: $conflicts);
        }

        $skippedLinks = [];
        $truncatedDirs = [];
        if (!$dryRun) {
            foreach ($skills as $skill) {
                $this->copyTree(
                    (string) $skill->sourceDir,
                    (string) $target->join($skill->name),
                    0,
                    $skippedLinks,
                    $truncatedDirs,
                );
            }
        }

        return new SyncReport(
            copied: $skills,
            conflicts: [],
            skippedLinks: $skippedLinks,
            truncatedDirs: $truncatedDirs,
        );
    }

    /**
     * @param list<Skill> $skills
     *
     * @return list<SkillConflict>
     *
     * @psalm-pure
     */
    private function detectConflicts(array $skills): array
    {
        /** @var array<non-empty-string, list<non-empty-string>> $byName */
        $byName = [];
        foreach ($skills as $skill) {
            $byName[$skill->name][] = $skill->packageName;
        }

        $conflicts = [];
        foreach ($byName as $name => $packages) {
            if (\count($packages) > 1) {
                $conflicts[] = new SkillConflict(name: $name, packages: $packages);
            }
        }

        return $conflicts;
    }

    /**
     * Recursively copy `$src` into `$dst`. Existing files in `$dst` that are
     * also in `$src` are overwritten (vendor is source of truth). Files in
     * `$dst` not present in `$src` are left untouched (non-destructive merge).
     *
     * Symlinks and NTFS junctions are never followed or copied. A link inside
     * a donor could point at a large or sensitive tree beyond the skill —
     * dragging unrelated content into the target — and a link cycle would
     * recurse forever. Each skipped link's source path is appended to
     * `$skippedLinks` so the caller can explain why a file did not arrive; a
     * silent security skip is a debugging trap.
     *
     * When the {@see self::MAX_COPY_DEPTH} backstop trips, the directory whose
     * contents were left uncopied is appended to `$truncatedDirs` for the same
     * reason: a truncated copy the user cannot see is a debugging trap too.
     *
     * @param int $depth levels below the skill root for `$src`; the recursion
     *        stops at {@see self::MAX_COPY_DEPTH} as a cycle backstop
     * @param list<string> $skippedLinks source paths of skipped links, accumulated across the tree
     * @param list<string> $truncatedDirs source paths where recursion stopped at the depth cap,
     *        accumulated across the tree
     *
     * @param-out list<string> $skippedLinks
     * @param-out list<string> $truncatedDirs
     */
    private function copyTree(
        string $src,
        string $dst,
        int $depth,
        array &$skippedLinks,
        array &$truncatedDirs,
    ): void {
        if ($depth >= self::MAX_COPY_DEPTH) {
            $truncatedDirs[] = $src;
            return;
        }

        $this->ensureDirectory($dst);

        $entries = \scandir($src);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $s = $src . \DIRECTORY_SEPARATOR . $entry;
            $d = $dst . \DIRECTORY_SEPARATOR . $entry;

            if (LinkGuard::isLink($s)) {
                $skippedLinks[] = $s;
                continue;
            }

            if (\is_dir($s)) {
                $this->copyTree($s, $d, $depth + 1, $skippedLinks, $truncatedDirs);
            } else {
                \copy($s, $d);
            }
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (!\is_dir($path) && !\mkdir($path, recursive: true)) {
            throw new \RuntimeException(\sprintf('Failed to create directory "%s"', $path));
        }
    }
}
