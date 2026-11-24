<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use Internal\Path;
use LLM\Skills\Discovery\InstalledSkill;
use LLM\Skills\Discovery\Skill;
use LLM\Skills\Discovery\SkillTreeScanner;
use LLM\Skills\Filesystem\DirectoryIdentity;
use LLM\Skills\Filesystem\LinkGuard;
use LLM\Skills\Filesystem\PathContainment;
use LLM\Skills\Filesystem\TreeRemover;

/**
 * Writes a curated list of skills into a target directory.
 *
 * Discovery is no longer the engine's job — callers pass already
 * enumerated {@see Skill}s (typically from
 * {@see \LLM\Skills\Discovery\SkillEnumerator}). The engine is three
 * phases:
 *
 *   1. Validate — detect skill-name collisions. Reported as
 *      {@see SkillConflict}s; sync aborts before any write.
 *   2. Purge    — delete the installed skills the caller listed (empty
 *      for a plain run; populated under `--clean`).
 *   3. Copy     — per skill: delete its installed directory, then copy the
 *      donor's. Skills the run does not write are left alone.
 *
 * Validation runs before the purge so a name collision — or a target
 * whose donors could not be enumerated — leaves the existing target
 * intact instead of emptying it with nothing to put back.
 *
 * Both Composer/Trust resolution and filesystem enumeration happen
 * **before** the engine. The engine is pure write logic plus conflict
 * detection — no knowledge of Composer, IO, or interactive prompts.
 */
final class SyncEngine
{
    /**
     * Directory depth {@see self::copyTree()} descends into a single skill
     * tree before giving up. Real skills are shallow, so this generous cap
     * never bites legitimate content; it is a defence-in-depth backstop so
     * an undetected reparse-point cycle terminates deterministically rather
     * than recursing until the stack or the path length gives out.
     */
    public const MAX_COPY_DEPTH = 32;

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly TreeRemover $remover = new TreeRemover(),
        private readonly PurgePlanner $purgePlanner = new PurgePlanner(),
    ) {}

    /**
     * @param list<Skill> $skills the skills to write (post-trust, post-enumeration)
     * @param Path $target absolute destination directory; created if missing
     * @param bool $dryRun when `true`, do everything except writing files — conflict detection still runs,
     *        and the returned report's `copied` / `removed` lists still name the skills that *would*
     *        have been written and deleted.
     * @param list<InstalledSkill> $purge installed skills to delete before copying, including ones
     *        no skill in `$skills` writes back; `[]` deletes only what the copy replaces
     * @param list<Skill> $bystanders skills of donors a scoped run leaves out, checked for conflicts only:
     *        writing a namesake of one would overwrite what a full run installs there
     */
    public function sync(
        array $skills,
        Path $target,
        bool $dryRun = false,
        array $purge = [],
        array $bystanders = [],
    ): SyncReport {
        $conflicts = $this->detectConflicts([...$skills, ...$bystanders]);
        if ($conflicts !== []) {
            return new SyncReport(copied: [], conflicts: $conflicts);
        }

        // A bystander's source counts too: the run does not write it back either.
        $incoming = [...$skills, ...$bystanders];
        [$purge, $kept] = $this->purgePlanner->partitionSourceHolders($purge, $incoming, $target);
        $keptSources = \array_map(static fn(InstalledSkill $s): string => $s->name, $kept);

        // A source that is the target itself has nowhere to be copied to.
        $targetAsSource = [];
        $copyable = [];
        foreach ($skills as $skill) {
            if (PathContainment::isSame($target, $skill->sourceDir)) {
                $targetAsSource[] = $skill->name;
            } else {
                $copyable[] = $skill;
            }
        }
        $skills = $copyable;

        $removed = [];
        $removalFailures = [];
        $skippedLinks = [];
        $truncatedDirs = [];
        $linkedDestinations = [];
        $copied = [];
        if (!$dryRun) {
            foreach ($purge as $installed) {
                if ($this->remover->remove((string) $installed->dir)) {
                    $removed[] = $installed->name;
                } else {
                    $removalFailures[] = $installed->name;
                }
            }

            if ($skills !== []) {
                // Created up front so the target resolves for the containment check.
                $this->ensureDirectory((string) $target);
            }
            $targetIdentity = DirectoryIdentity::of($target);
            $replaceable = $this->replaceable($skills, $target, [...$purge, ...$kept], $incoming, $keptSources);
            foreach ($skills as $skill) {
                $destination = $target->join($skill->name);
                if (PathContainment::isSame($destination, $skill->sourceDir)) {
                    // Already in place; copying a file onto itself truncates it.
                    $copied[] = $skill;
                    continue;
                }
                if (LinkGuard::isLink((string) $destination)) {
                    $linkedDestinations[] = (string) $destination;
                    continue;
                }
                if (isset($replaceable[$skill->name]) && !$this->remover->remove((string) $destination)) {
                    $removalFailures[] = $skill->name;
                }
                $copied[] = $skill;
                $this->copyTree(
                    (string) $skill->sourceDir,
                    (string) $destination,
                    PathContainment::isWithin($target, $skill->sourceDir) ? $targetIdentity : null,
                    0,
                    $skippedLinks,
                    $truncatedDirs,
                    $linkedDestinations,
                );
            }
        } else {
            $removed = \array_map(static fn(InstalledSkill $s): string => $s->name, $purge);
            $this->replaceable($skills, $target, [...$purge, ...$kept], $incoming, $keptSources);
            // The dry run does not walk the skills, so it names a link only when
            // it is the skill directory, which decides whether the skill counts
            // as copied. The purge strips a linked skill directory as a link,
            // so a skill about to be purged is written into a fresh directory.
            $purgedKeys = [];
            foreach ($purge as $installed) {
                $purgedKeys[SkillConflict::collisionKey($installed->name)] = true;
            }
            foreach ($skills as $skill) {
                $destination = $target->join($skill->name);
                if (!isset($purgedKeys[SkillConflict::collisionKey($skill->name)])
                    && !PathContainment::isSame($destination, $skill->sourceDir)
                    && LinkGuard::isLink((string) $destination)
                ) {
                    $linkedDestinations[] = (string) $destination;
                    continue;
                }
                $copied[] = $skill;
            }
        }

        return new SyncReport(
            copied: $copied,
            conflicts: [],
            skippedLinks: $skippedLinks,
            truncatedDirs: $truncatedDirs,
            removed: $removed,
            removalFailures: $removalFailures,
            keptSources: $keptSources,
            targetAsSource: $targetAsSource,
            linkedDestinations: $linkedDestinations,
        );
    }

    /**
     * Names of the skills whose installed directory is deleted right before the
     * copy, so a file the donor stopped shipping does not outlive the update.
     *
     * Left out: a directory without `SKILL.md`, which is no installed skill and is
     * merged into as `--clean` leaves it alone; a directory the purge already took
     * or failed to take; one that is or holds the source of an incoming skill
     * (appended to `$keptSources`); one whose donor copy has no `SKILL.md` the copy
     * would bring along — no skill would take the place of a working one; and one
     * whose donor tree cannot be read in full, where the copy would fail half way
     * after the delete.
     *
     * @param list<Skill> $skills the skills about to be copied
     * @param list<InstalledSkill> $purged the purge list, deleted or not
     * @param list<Skill> $incoming every skill whose source must survive, bystanders included
     * @param list<non-empty-string> $keptSources
     *
     * @param-out list<non-empty-string> $keptSources
     *
     * @return array<non-empty-string, true>
     */
    private function replaceable(
        array $skills,
        Path $target,
        array $purged,
        array $incoming,
        array &$keptSources,
    ): array {
        $candidates = [];
        foreach ($skills as $skill) {
            $destination = $target->join($skill->name);
            if (!\is_file((string) $destination->join('SKILL.md'))
                || LinkGuard::isLink((string) $destination)
                || PathContainment::isSame($destination, $skill->sourceDir)
                || $this->isPurged($skill->name, $destination, $purged)
                || !SkillTreeScanner::holdsSkillMd($skill->sourceDir)
                || !$this->isReadableTree(
                    (string) $skill->sourceDir,
                    PathContainment::isWithin($target, $skill->sourceDir) ? DirectoryIdentity::of($target) : null,
                    0,
                )
            ) {
                continue;
            }
            $candidates[] = new InstalledSkill($skill->name, $destination);
        }

        [$deletable, $kept] = $this->purgePlanner->partitionSourceHolders($candidates, $incoming, $target);
        foreach ($kept as $installed) {
            $keptSources[] = $installed->name;
        }

        $names = [];
        foreach ($deletable as $installed) {
            $names[$installed->name] = true;
        }

        return $names;
    }

    /**
     * Whether {@see self::copyTree()} can read everything it would copy from `$src`:
     * the same walk, skipping links, the excluded target and what lies past the
     * depth cap.
     */
    private function isReadableTree(string $src, ?DirectoryIdentity $excludedDir, int $depth): bool
    {
        if ($depth >= self::MAX_COPY_DEPTH) {
            return true;
        }

        $entries = @\scandir($src);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $src . \DIRECTORY_SEPARATOR . $entry;
            if (LinkGuard::isLink($path)) {
                continue;
            }
            if (!\is_dir($path)) {
                if (!\is_readable($path)) {
                    return false;
                }
                continue;
            }
            if ($excludedDir?->matches(Path::create($path)) !== true
                && !$this->isReadableTree($path, $excludedDir, $depth + 1)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<InstalledSkill> $purged
     */
    private function isPurged(string $name, Path $destination, array $purged): bool
    {
        foreach ($purged as $installed) {
            if ($installed->name === $name || PathContainment::isSame($installed->dir, $destination)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Names are compared under {@see SkillConflict::collisionKey()}: two names
     * one file system stores as the same directory would merge into it.
     *
     * @param list<Skill> $skills
     *
     * @return list<SkillConflict>
     *
     * @psalm-pure
     */
    private function detectConflicts(array $skills): array
    {
        /** @var array<string, list<non-empty-string>> $packagesByKey */
        $packagesByKey = [];
        /** @var array<string, non-empty-list<non-empty-string>> $namesByKey */
        $namesByKey = [];
        foreach ($skills as $skill) {
            $key = SkillConflict::collisionKey($skill->name);
            $packagesByKey[$key][] = $skill->packageName;
            $namesByKey[$key][] = $skill->name;
        }

        $conflicts = [];
        foreach ($packagesByKey as $key => $packages) {
            if (\count($packages) > 1) {
                $names = $namesByKey[$key];
                $conflicts[] = new SkillConflict(name: $names[0], packages: $packages, names: $names);
            }
        }

        return $conflicts;
    }

    /**
     * Recursively copy `$src` into `$dst`. Existing files in `$dst` that are
     * also in `$src` are overwritten (vendor is source of truth); others are left
     * untouched, which matters only where the replace kept the directory.
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
     * Links on the destination side are never written through either. A link
     * the user made inside the target, such as a skill directory pointing at a
     * local checkout, leads outside it, so writing into the link would overwrite
     * whatever it points at. The entry is left as it is and its destination path
     * appended to `$linkedDestinations`; `$dst` itself is checked by the caller.
     * A hard-linked file is unlinked before the copy, which leaves its other
     * names intact; one that cannot be unlinked is left and reported the same way.
     *
     * The target directory is never copied: a donor that contains it, such as a
     * root skill whose directory is the project, would otherwise copy the target
     * into one of its own skills, and again on every level below. Matching the
     * target itself is enough: the walk follows no link in the source, so the
     * target is reachable only at its own path.
     *
     * @param DirectoryIdentity|null $excludedDir the target when the skill source
     *        contains it, `null` when the source cannot reach it
     * @param int $depth levels below the skill root for `$src`; the recursion
     *        stops at {@see self::MAX_COPY_DEPTH} as a cycle backstop
     * @param list<string> $skippedLinks source paths of skipped links, accumulated across the tree
     * @param list<string> $truncatedDirs source paths where recursion stopped at the depth cap,
     *        accumulated across the tree
     * @param list<string> $linkedDestinations destination paths of links left unwritten,
     *        accumulated across the tree
     *
     * @param-out list<string> $skippedLinks
     * @param-out list<string> $truncatedDirs
     * @param-out list<string> $linkedDestinations
     */
    private function copyTree(
        string $src,
        string $dst,
        ?DirectoryIdentity $excludedDir,
        int $depth,
        array &$skippedLinks,
        array &$truncatedDirs,
        array &$linkedDestinations,
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
            // `/` as in the target's `Internal\Path`, so a reported link does not mix separators.
            $d = $dst . '/' . $entry;

            if (LinkGuard::isLink($s)) {
                $skippedLinks[] = $s;
                continue;
            }

            $isDir = \is_dir($s);
            if ($isDir && $excludedDir?->matches(Path::create($s)) === true) {
                continue;
            }

            if (LinkGuard::isLink($d)) {
                $linkedDestinations[] = $d;
                continue;
            }

            if ($isDir) {
                $this->copyTree(
                    $s,
                    $d,
                    $excludedDir,
                    $depth + 1,
                    $skippedLinks,
                    $truncatedDirs,
                    $linkedDestinations,
                );
            } elseif ($this->detachHardLink($d)) {
                \copy($s, $d);
            } else {
                $linkedDestinations[] = $d;
            }
        }
    }

    /**
     * Gives `$path` an inode of its own when it is a hard link, so the copy
     * replaces the file instead of truncating an inode another name shares,
     * possibly outside the target. A directory is never a hard link, though on
     * POSIX its link count is two or more.
     *
     * @return bool `true` when `$path` can be written without reaching another name
     */
    private function detachHardLink(string $path): bool
    {
        if (\is_dir($path)) {
            return true;
        }

        $stat = @\stat($path);
        if ($stat === false || $stat['nlink'] <= 1) {
            return true;
        }

        return @\unlink($path);
    }

    private function ensureDirectory(string $path): void
    {
        if (!\is_dir($path) && !\mkdir($path, recursive: true)) {
            throw new \RuntimeException(\sprintf('Failed to create directory "%s"', $path));
        }
    }
}
