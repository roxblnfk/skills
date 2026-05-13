<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo;

/**
 * Filesystem helpers shared by acceptance tests and testo interceptors.
 *
 * The recursive delete is junction-safe — important on Windows where path-repo
 * dependencies (`vendor/llm/skills`, `vendor/acme/skills-*`) are joined into
 * the sandbox via NTFS directory junctions. Naive descend-and-unlink would
 * walk through a junction and start deleting the plugin's own source tree.
 */
final class Filesystem
{
    /**
     * Recursively remove a file, directory, symlink, or NTFS junction.
     *
     * Idempotent: missing paths are a no-op. All filesystem errors are
     * silenced — partial failure leaves an incomplete tree on disk, which the
     * caller can decide how to handle on the next run.
     */
    public static function removeRecursive(string $path): void
    {
        // Check for link FIRST — for an NTFS junction this PHP build returns
        // false from is_link, is_dir, is_file and file_exists, yet rmdir()
        // will strip the link cleanly.
        if (self::isLink($path)) {
            // Strip the link itself, never recurse into its target. rmdir()
            // handles dir-junctions and dir-symlinks; unlink() covers file links.
            if (!@\rmdir($path)) {
                @\unlink($path);
            }
            return;
        }

        if (\is_file($path)) {
            @\unlink($path);
            return;
        }

        if (!\is_dir($path)) {
            return;
        }

        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeRecursive($path . '/' . $entry);
        }

        @\rmdir($path);
    }

    /**
     * Robust link detection for NTFS junctions.
     *
     * In this PHP build `is_link`, `is_dir` and `is_file` all return false for a
     * directory junction, and `readlink` on a regular directory returns the dir's
     * own path (not `false`), so neither check is enough. The reliable signal is
     * that `realpath()` on a junction resolves to a path *outside* the parent.
     */
    private static function isLink(string $path): bool
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
