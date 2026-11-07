<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use Internal\Path;
use LLM\Skills\Discovery\InstalledSkill;
use LLM\Skills\Discovery\Skill;
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
 *   3. Copy     — recursive, non-destructive merge per skill.
 *
 * Validation runs before the purge so a name collision — or a target
 * whose donors could not be enumerated — leaves the existing target
 * intact instead of emptying it with nothing to put back.
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
    public const MAX_COPY_DEPTH = 32;

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private TreeRemover $remover = new TreeRemover(),
        private PurgePlanner $purgePlanner = new PurgePlanner(),
    ) {}

    /**
     * @param list<Skill> $skills the skills to write (post-trust, post-enumeration)
     * @param Path $target absolute destination directory; created if missing
     * @param bool $dryRun when `true`, do everything except writing files — conflict detection still runs,
     *        and the returned report's `copied` / `removed` lists still name the skills that *would*
     *        have been written and deleted.
     * @param list<InstalledSkill> $purge installed skills to delete before copying; `[]` leaves the
     *        target's existing content in place, which is the default non-destructive merge
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

        [$purge, $kept] = $this->purgePlanner->partitionSourceHolders($purge, $skills, $target);
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
            foreach ($skills as $skill) {
                $destination = $target->join($skill->name);
                if (PathContainment::isSame($destination, $skill->sourceDir)) {
                    // Already in place; copying a file onto itself truncates it.
                    continue;
                }
                $this->copyTree(
                    (string) $skill->sourceDir,
                    (string) $destination,
                    PathContainment::isWithin($target, $skill->sourceDir) ? $targetIdentity : null,
                    0,
                    $skippedLinks,
                    $truncatedDirs,
                );
            }
        } else {
            $removed = \array_map(static fn(InstalledSkill $s): string => $s->name, $purge);
        }

        return new SyncReport(
            copied: $skills,
            conflicts: [],
            skippedLinks: $skippedLinks,
            truncatedDirs: $truncatedDirs,
            removed: $removed,
            removalFailures: $removalFailures,
            keptSources: $keptSources,
            targetAsSource: $targetAsSource,
        );
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
     * The target directory is never copied: a donor that contains it, such as a
     * root skill whose directory is the project, would otherwise copy the target
     * into one of its own skills, and again on every level below. Matching the
     * target itself is enough, since links are never followed and so nothing
     * inside it is reachable any other way.
     *
     * @param DirectoryIdentity|null $excludedDir the target when the skill source
     *        contains it, `null` when the source cannot reach it
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
        ?DirectoryIdentity $excludedDir,
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
                if ($excludedDir?->matches(Path::create($s)) === true) {
                    continue;
                }
                $this->copyTree($s, $d, $excludedDir, $depth + 1, $skippedLinks, $truncatedDirs);
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
