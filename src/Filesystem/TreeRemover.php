<?php

declare(strict_types=1);

namespace LLM\Skills\Filesystem;

/**
 * Recursively deletes a skill tree without ever traversing a link.
 *
 * A symlink or NTFS junction under the tree is stripped as a link — the
 * directory it points at is left alone. Descending would delete content
 * outside the tree the caller asked about, and a link cycle would recurse
 * until the stack or the path length gave out.
 */
final readonly class TreeRemover
{
    /**
     * Directory depth {@see self::remove()} descends before giving up. Skill
     * bundles are shallow, so the cap only ever trips on a reparse-point cycle
     * that {@see LinkGuard} failed to recognise; hitting it fails the removal
     * rather than recursing indefinitely.
     */
    private const MAX_REMOVE_DEPTH = 32;

    /**
     * @return bool `true` when nothing remains at `$path`; `false` when some
     *         entry survived — a permission error, an open handle on Windows,
     *         or the depth cap. A partial removal is left on disk for the
     *         caller to report.
     */
    public function remove(string $path): bool
    {
        return $this->removeTree($path, 0);
    }

    /**
     * @param int $depth levels below the root passed to {@see self::remove()}
     */
    private function removeTree(string $path, int $depth): bool
    {
        if (LinkGuard::isLink($path)) {
            // A directory link needs rmdir and a file link needs unlink, and
            // whether is_dir sees a junction depends on the PHP build — so try
            // both rather than branching on a stat.
            return @\rmdir($path) || @\unlink($path);
        }

        if (\is_file($path)) {
            // Windows refuses to unlink a read-only file until the attribute is cleared.
            return @\unlink($path)
                || \DIRECTORY_SEPARATOR === '\\' && @\chmod($path, 0o666) && @\unlink($path);
        }

        if (!\is_dir($path)) {
            return true;
        }

        if ($depth >= self::MAX_REMOVE_DEPTH) {
            return false;
        }

        // Silenced: Composer turns the warning into an exception, and `false` is reported below.
        $entries = @\scandir($path);
        if ($entries === false) {
            return false;
        }

        $complete = true;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $complete = $this->removeTree($path . \DIRECTORY_SEPARATOR . $entry, $depth + 1) && $complete;
        }

        // rmdir only succeeds on an empty directory, so a survivor below
        // propagates up as a failure on its own.
        // A read-only directory refuses rmdir on Windows the same way a read-only file refuses unlink.
        $gone = @\rmdir($path)
            || \DIRECTORY_SEPARATOR === '\\' && @\chmod($path, 0o777) && @\rmdir($path);

        return $gone && $complete;
    }
}
