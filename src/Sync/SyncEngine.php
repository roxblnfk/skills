<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use Internal\Path;
use LLM\Skills\Discovery\Skill;

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

        if (!$dryRun) {
            foreach ($skills as $skill) {
                $this->copyTree(
                    (string) $skill->sourceDir,
                    (string) $target->join($skill->name),
                );
            }
        }

        return new SyncReport(copied: $skills, conflicts: []);
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
     */
    private function copyTree(string $src, string $dst): void
    {
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

            if (\is_dir($s)) {
                $this->copyTree($s, $d);
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
