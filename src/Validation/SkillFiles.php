<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

/**
 * Directory reads the validator shares: listing entries and finding skill directories.
 * Paths are absolute, normalized forward-slash strings, see {@see PathDisplay}.
 *
 * @internal
 */
final class SkillFiles
{
    /** Directory names a search for skills never enters. */
    public const SKIP_DIRS = ['vendor', 'node_modules', '.git'];

    /**
     * Sorted entry names of a directory, without `.` and `..`; empty when it cannot be read.
     *
     * @return list<string>
     */
    public static function entries(string $dir): array
    {
        $list = @\scandir($dir);
        if ($list === false) {
            return [];
        }
        $list = \array_values(\array_diff($list, ['.', '..']));
        \sort($list, \SORT_STRING);

        return $list;
    }

    /**
     * The on-disk name of the skill file in `$dir`: `SKILL.md`, else a differently cased
     * variant such as `skill.md`; `null` when there is none.
     */
    public static function skillFileName(string $dir): ?string
    {
        $found = null;
        foreach (self::entries($dir) as $entry) {
            if (\strcasecmp($entry, 'SKILL.md') !== 0 || !\is_file("$dir/$entry")) {
                continue;
            }
            if ($entry === 'SKILL.md') {
                return $entry;
            }
            $found ??= $entry;
        }

        return $found;
    }

    /**
     * The last segment of `$path` spelled as on disk. On a case-insensitive file system the
     * user may type `My-Skill` for `my-skill`; the name checks need the stored spelling.
     */
    public static function onDiskName(string $path): string
    {
        $typed = PathDisplay::name($path);
        $found = null;
        $parent = \rtrim(PathDisplay::parent($path), '/');
        foreach (self::entries($parent . '/') as $entry) {
            if ($entry === $typed) {
                return $entry;
            }
            if (\strcasecmp($entry, $typed) === 0 && self::identity("$parent/$entry") === self::identity($path)) {
                $found ??= $entry;
            }
        }

        return $found ?? $typed;
    }

    /**
     * A key equal for every spelling of one file or directory: its device and inode where the
     * platform reports them, else its real path case-folded on Windows. The real path alone
     * is not enough: on macOS `realpath()` keeps the letter case it was given.
     */
    public static function identity(string $dir): string
    {
        $stat = @\stat($dir);
        if ($stat !== false && $stat['ino'] > 0) {
            return $stat['dev'] . ':' . $stat['ino'];
        }
        $real = \realpath($dir);
        $key = $real === false ? $dir : PathDisplay::normalize($real);

        return \PHP_OS_FAMILY === 'Windows' ? \strtolower($key) : $key;
    }

    /**
     * Skill directories at or under `$path`: the directory holding a given `SKILL.md`, the
     * path itself when it holds one, else a walk that does not descend into skills, into
     * directories already seen through a link, or into {@see self::SKIP_DIRS}.
     *
     * A hidden skill directory met on the walk goes to `$hidden` instead: llm/skills never
     * installs one, so it is a draft or a parked copy rather than a skill to validate.
     *
     * @param string $path absolute and normalized
     * @param list<string> $hidden receives the hidden skill directories the walk passed over
     *
     * @return list<string>
     *
     * @param-out list<string> $hidden
     */
    public static function discover(string $path, int $maxDepth = 6, ?array &$hidden = null): array
    {
        $hidden = [];
        if (\is_file($path)) {
            return \strcasecmp(PathDisplay::name($path), 'SKILL.md') === 0 ? [PathDisplay::parent($path)] : [];
        }
        if (!\is_dir($path)) {
            return [];
        }
        $found = [];
        $visited = [];
        $walk = static function (string $dir, int $depth) use (&$walk, &$found, &$hidden, &$visited, $maxDepth): void {
            $key = self::identity($dir);
            if (isset($visited[$key])) {
                return;
            }
            $visited[$key] = true;
            if (self::skillFileName($dir) !== null) {
                if ($depth > 0 && PathDisplay::name($dir)[0] === '.') {
                    $hidden[] = $dir;
                } else {
                    $found[] = $dir;
                }

                return;
            }
            if ($depth >= $maxDepth) {
                return;
            }
            foreach (self::entries($dir) as $entry) {
                if (\in_array($entry, self::SKIP_DIRS, true) || !\is_dir("$dir/$entry")) {
                    continue;
                }
                /** @var \Closure(string, int): void $walk */
                $walk("$dir/$entry", $depth + 1);
            }
        };
        $walk($path, 0);

        /** @var list<string> $hidden */
        /** @var list<string> $found */
        return $found;
    }
}
