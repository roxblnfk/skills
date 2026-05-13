<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use Internal\Path;
use LLM\Skills\Config\VendorConfig;

/**
 * Copies AI skills from a curated list of donor packages into a target
 * directory. Three-phase, transactional w.r.t. conflicts:
 *
 *   1. Discover  — enumerate skill subdirectories of each donor's `source`.
 *   2. Validate  — detect skill-name collisions across donors.
 *   3. Copy      — only if no conflicts; otherwise abort with the conflict list.
 *
 * Trust resolution and CLI filtering happen **before** this engine: callers
 * pass an already-vetted list of donors. The engine is pure logic over a
 * filesystem — no knowledge of Composer, IO interfaces, or interactive
 * prompts.
 */
final readonly class SyncEngine
{
    /**
     * @param list<VendorConfig> $donors curated donor packages (trust + filters already applied)
     * @param Path               $target absolute destination directory; created if missing
     */
    public function sync(array $donors, Path $target): SyncReport
    {
        $warnings = [];
        $skills = $this->discover($donors, $warnings);

        $conflicts = $this->detectConflicts($skills);
        if ($conflicts !== []) {
            return new SyncReport(copied: [], conflicts: $conflicts, warnings: $warnings);
        }

        foreach ($skills as $skill) {
            $this->copyTree(
                (string) $skill->sourceDir,
                (string) $target->join($skill->name),
            );
        }

        return new SyncReport(copied: $skills, conflicts: [], warnings: $warnings);
    }

    /**
     * @param list<VendorConfig> $donors
     * @param list<string>       $warnings   accumulator, mutated in place
     *
     * @return list<Skill>
     */
    private function discover(array $donors, array &$warnings): array
    {
        $skills = [];
        foreach ($donors as $donor) {
            $sourcePath = (string) $donor->sourcePath();

            if (!\is_dir($sourcePath)) {
                $warnings[] = \sprintf(
                    '%s: source directory "%s" does not exist',
                    $donor->packageName,
                    $donor->source,
                );
                continue;
            }

            $entries = \scandir($sourcePath);
            if ($entries === false) {
                $warnings[] = \sprintf(
                    '%s: source directory "%s" is unreadable',
                    $donor->packageName,
                    $donor->source,
                );
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $skillPath = $sourcePath . \DIRECTORY_SEPARATOR . $entry;
                if (!\is_dir($skillPath)) {
                    // Files at the source root are not skills (spec §1). Ignore silently.
                    continue;
                }

                /** @var non-empty-string $entry */
                $skills[] = new Skill(
                    name: $entry,
                    sourceDir: Path::create($skillPath),
                    packageName: $donor->packageName,
                );
            }
        }

        return $skills;
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
