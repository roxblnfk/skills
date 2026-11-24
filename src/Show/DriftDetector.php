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
 * donor source — i.e. whether the next sync would change it: a donor file
 * is missing locally or its bytes differ, or the target holds an entry the
 * donor does not ship, which the sync replaces the directory to drop. A link
 * inside the installed skill counts as such an entry.
 *
 * The comparison is plain byte-equality, walked recursively. Hashing
 * was considered and rejected: skill bundles are small (a handful of
 * `.md` files), so the I/O cost is the same and direct comparison
 * fails fast at the first differing file without a separate hashing
 * pass.
 */
final class DriftDetector
{
    /**
     * @param bool $replaced whether the sync replaces the installed directory; `false` for one it
     *        copies into instead, where extra entries and links survive the sync
     *
     * @return bool `true` when at least one donor-owned file is missing from the target or has
     *         different bytes, or, for a replaced directory, the target holds an entry the donor
     *         does not ship
     */
    public function differs(Path $donorSkillDir, Path $installedSkillDir, bool $replaced = true): bool
    {
        // A skill directory linked to its own source is in place; the sync leaves it as it is.
        if (PathContainment::isSame($donorSkillDir, $installedSkillDir)) {
            return false;
        }

        // The installed skill sits directly in the target. A donor that contains
        // the target never has it copied, so the walk must not expect it either.
        $target = $installedSkillDir->parent();
        $excluded = PathContainment::isWithin($target, $donorSkillDir) ? DirectoryIdentity::of($target) : null;

        return $this->walkAndCompare((string) $donorSkillDir, (string) $installedSkillDir, $excluded, 0, $replaced);
    }

    /**
     * Walks exactly what {@see SyncEngine} copies: links in the donor are skipped
     * and the walk stops at the same depth, so content the sync never writes is
     * never expected in the target. A skill whose own directory in the target is
     * a link elsewhere is never synced, so callers leave it out instead of asking.
     *
     * @param DirectoryIdentity|null $excludedDir the target when the donor contains it
     * @param int $depth levels below the skill root for `$donorDir`
     * @param bool $replaced see {@see self::differs()}
     */
    private function walkAndCompare(
        string $donorDir,
        string $installedDir,
        ?DirectoryIdentity $excludedDir,
        int $depth,
        bool $replaced,
    ): bool {
        // Silenced: Composer turns the warning into an exception; `false` is handled below.
        $entries = @\scandir($donorDir);
        if ($entries === false) {
            // Donor directory unreadable — caller should not have asked us in
            // the first place. Treat as "we can't tell" → conservatively report
            // drift so the user is alerted.
            return true;
        }

        $shipped = [];
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
                $shipped[$entry] = true;
                if (LinkGuard::isLink($installedPath)) {
                    if ($replaced) {
                        return true;
                    }
                    continue;
                }
                if (!\is_dir($installedPath)) {
                    return true;
                }
                if ($this->walkAndCompare($donorPath, $installedPath, $excludedDir, $depth + 1, $replaced)) {
                    return true;
                }
                continue;
            }

            $shipped[$entry] = true;
            if (LinkGuard::isLink($installedPath)) {
                if ($replaced) {
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

        if (!$replaced) {
            return false;
        }

        $installedEntries = @\scandir($installedDir);
        if ($installedEntries === false) {
            return true;
        }
        foreach ($installedEntries as $entry) {
            if ($entry !== '.' && $entry !== '..' && !isset($shipped[$entry])) {
                return true;
            }
        }

        return false;
    }
}
