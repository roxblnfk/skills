<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use Internal\Path;

/**
 * Lists skills that are *currently sitting* in the project's target
 * directory.
 *
 * A directory under `$target` is considered an installed skill iff it
 * contains a `SKILL.md` file at its root. This mirrors what donors ship
 * (every skill bundle has a `SKILL.md`) and avoids picking up unrelated
 * subfolders the user may have placed under the target dir.
 *
 * Used by the `show` command to mark each donor-side skill as `[✓]`
 * (already installed) or `[ ]` (would be written on next sync).
 */
final readonly class InstalledSkillScanner
{
    /**
     * @return list<InstalledSkill> empty when `$target` does not exist
     */
    public function scan(Path $target): array
    {
        $base = (string) $target;
        if (!\is_dir($base)) {
            return [];
        }

        $entries = \scandir($base);
        if ($entries === false) {
            return [];
        }

        $result = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $base . \DIRECTORY_SEPARATOR . $entry;
            if (!\is_dir($dir)) {
                continue;
            }
            if (!\is_file($dir . \DIRECTORY_SEPARATOR . 'SKILL.md')) {
                // A directory without SKILL.md is not a skill, even if it's
                // sitting under the skills target. Could be an in-progress
                // local thing the user is editing.
                continue;
            }

            /** @var non-empty-string $entry */
            $result[] = new InstalledSkill(
                name: $entry,
                dir: Path::create($dir),
            );
        }

        return $result;
    }
}
