<?php

declare(strict_types=1);

namespace LLM\Skills\Filesystem;

/**
 * Detects filesystem links — native symlinks and NTFS junctions — so
 * traversal code can refuse to follow them.
 *
 * Following a link while copying or scanning a skill tree is a security
 * hazard: a link inside a donor can point at a large or sensitive tree
 * outside the skill, dragging unrelated content into the target, and a
 * link cycle can spin traversal forever. Callers skip any entry this
 * reports as a link.
 *
 * Detection has to cope with an NTFS directory junction (`mklink /J`),
 * which a vendor package can ship without administrator rights. On the
 * Windows PHP build this project targets a junction returns `false` from
 * {@see \is_link()}, {@see \is_dir()} and {@see \is_file()} alike, and
 * {@see \readlink()} on an ordinary directory returns the directory's own
 * path rather than `false` — so neither of those is a reliable signal.
 * The dependable one is that {@see \realpath()} resolves a junction to a
 * path other than its own literal location.
 */
final class LinkGuard
{
    /**
     * `true` when `$path` is a symlink or an NTFS junction.
     */
    public static function isLink(string $path): bool
    {
        if (\is_link($path)) {
            return true;
        }

        $parent = \realpath(\dirname($path));
        $resolved = \realpath($path);
        if ($parent === false || $resolved === false) {
            return false;
        }

        $expected = $parent . \DIRECTORY_SEPARATOR . \basename($path);

        return \strcasecmp($resolved, $expected) !== 0;
    }
}
