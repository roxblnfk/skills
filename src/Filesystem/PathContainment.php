<?php

declare(strict_types=1);

namespace LLM\Skills\Filesystem;

use Internal\Path;

/**
 * Answers whether one existing path lies inside another, as the file system
 * sees them rather than as they are spelled.
 */
final class PathContainment
{
    /**
     * `true` when `$path` is `$dir` or lies below it; `false` when either does
     * not exist.
     *
     * `$path` and each of its ancestors are matched against `$dir` by device and
     * inode, which is what the file system itself goes by. A spelling cannot: on
     * macOS `realpath()` keeps the caller's case although the volume folds it,
     * and a Windows directory can be case-sensitive, where `Foo` and `foo` are
     * two directories. Only a file system that reports no inode falls back to
     * comparing the resolved paths, segment by segment.
     */
    public static function isWithin(Path $path, Path $dir): bool
    {
        $pathReal = \realpath((string) $path);
        $dirReal = \realpath((string) $dir);
        if ($pathReal === false || $pathReal === '' || $dirReal === false || $dirReal === '') {
            return false;
        }

        $dirStat = @\stat($dirReal);
        if ($dirStat === false) {
            return false;
        }
        if ($dirStat['ino'] === 0) {
            return Path::create($pathReal)->isWithin($dirReal);
        }

        for ($current = $pathReal; ; $current = $parent) {
            $stat = @\stat($current);
            if ($stat !== false && $stat['dev'] === $dirStat['dev'] && $stat['ino'] === $dirStat['ino']) {
                return true;
            }
            $parent = \dirname($current);
            if ($parent === $current) {
                return false;
            }
        }
    }

    /**
     * `true` when both paths exist and resolve to the same directory or file.
     */
    public static function isSame(Path $a, Path $b): bool
    {
        return self::isWithin($a, $b) && self::isWithin($b, $a);
    }
}
