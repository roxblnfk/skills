<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

use Internal\Path;
use LLM\Skills\Filesystem\DirectoryIdentity;
use LLM\Skills\Filesystem\LinkGuard;
use LLM\Skills\Filesystem\PathContainment;
use LLM\Skills\Sync\SyncEngine;

/**
 * Detects whether an installed skill directory has *drifted* from its
 * donor source — i.e. some donor-owned file is missing locally or its
 * bytes differ.
 *
 * Files that exist only in the target are **not** drift. `skills:update`
 * is a non-destructive merge: it leaves user-added files alone. So a
 * locally edited `notes.md` next to `SKILL.md` does not warrant the
 * `[~]` marker.
 *
 * The comparison is plain byte-equality, walked recursively. Hashing
 * was considered and rejected: skill bundles are small (a handful of
 * `.md` files), so the I/O cost is the same and direct comparison
 * fails fast at the first differing file without a separate hashing
 * pass.
 */
final readonly class DriftDetector
{
    /**
     * @return bool `true` when at least one donor-owned file is missing
     *              from the target or has different bytes; `false` when
     *              every donor file is present in target with identical
     *              content.
     */
    public function differs(Path $donorSkillDir, Path $installedSkillDir): bool
    {
        // The installed skill sits directly in the target. A donor that contains
        // the target never has it copied, so the walk must not expect it either.
        $target = $installedSkillDir->parent();
        $excluded = PathContainment::isWithin($target, $donorSkillDir) ? DirectoryIdentity::of($target) : null;

        return $this->walkAndCompare((string) $donorSkillDir, (string) $installedSkillDir, $excluded, 0);
    }

    /**
     * Walks exactly what {@see SyncEngine} copies: links are skipped and the
     * walk stops at the same depth, so content the sync never writes is never
     * expected in the target.
     *
     * @param DirectoryIdentity|null $excludedDir the target when the donor contains it
     * @param int $depth levels below the skill root for `$donorDir`
     */
    private function walkAndCompare(
        string $donorDir,
        string $installedDir,
        ?DirectoryIdentity $excludedDir,
        int $depth,
    ): bool {
        $entries = \scandir($donorDir);
        if ($entries === false) {
            // Donor directory unreadable — caller should not have asked us in
            // the first place. Treat as "we can't tell" → conservatively report
            // drift so the user is alerted.
            return true;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $donorPath = $donorDir . \DIRECTORY_SEPARATOR . $entry;
            $installedPath = $installedDir . \DIRECTORY_SEPARATOR . $entry;

            if (LinkGuard::isLink($donorPath)) {
                continue;
            }

            if (\is_dir($donorPath)) {
                if ($depth + 1 >= SyncEngine::MAX_COPY_DEPTH) {
                    continue;
                }
                if ($excludedDir?->matches(Path::create($donorPath)) === true) {
                    continue;
                }
                if (!\is_dir($installedPath)) {
                    return true;
                }
                if ($this->walkAndCompare($donorPath, $installedPath, $excludedDir, $depth + 1)) {
                    return true;
                }
                continue;
            }

            if (!\is_file($installedPath)) {
                return true;
            }
            if (\filesize($donorPath) !== \filesize($installedPath)) {
                return true;
            }
            if (\file_get_contents($donorPath) !== \file_get_contents($installedPath)) {
                return true;
            }
        }

        return false;
    }
}
