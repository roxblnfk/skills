<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use Internal\Path;

/**
 * Creates one OS-level link per alias, pointing at the copy target.
 *
 * Runs **after** {@see SyncEngine} so the link target exists by the
 * time we create the alias. One alias = one junction/symlink, never
 * per-skill.
 *
 * Platform split:
 *
 * - **Windows**: directory **junctions** via `mklink /J`. Junctions
 *   work without admin/dev-mode (unlike symbolic links, which need
 *   `SeCreateSymbolicLink`). They are local-FS-only — if the alias
 *   and target would live on different volumes, the link cannot be
 *   created and the linker emits a {@see LinkStatus::Failed} result
 *   rather than silently degrading to a copy.
 * - **POSIX**: standard {@see \symlink()}.
 *
 * State matrix — applied to the alias path *before* creating
 * anything:
 *
 * | Existing state                  | Action                          |
 * |---------------------------------|---------------------------------|
 * | Does not exist                  | Create the link.                |
 * | Symlink/junction → target       | No-op, success.                 |
 * | Symlink/junction → elsewhere    | Failed (do not overwrite).      |
 * | Real directory                  | Failed (would destroy content). |
 * | Regular file                    | Failed.                         |
 *
 * No "force" mode: the plugin never destroys user-owned content. To
 * replace a real directory with an alias the user removes the
 * directory themselves and re-runs.
 */
final readonly class SymlinkLinker
{
    /**
     * Try to make `$alias` point at `$target`.
     *
     * The linker never throws on filesystem-level failures — every
     * failure path is captured in the returned {@see LinkResult} so
     * the runner can decide policy (warn vs. fail-run) in one place.
     *
     * @param Path $target absolute path that the alias must point at; the linker assumes
     *         the directory exists (callers run this after {@see SyncEngine} has copied)
     * @param bool $dryRun when `true`, the linker performs every read-only check but never
     *         writes; a would-be-created alias is reported as {@see LinkStatus::WouldCreate}
     */
    public function link(Path $alias, Path $target, bool $dryRun = false): LinkResult
    {
        // Use native separators throughout — PHP's `is_dir`/`is_link` on
        // Windows return *false* for an NTFS junction reached via a
        // forward-slash path, even though the junction is perfectly valid.
        // {@see \Internal\Path} normalises to forward slashes, so we
        // re-platformise here once and operate on a single native form for
        // every filesystem check below.
        $aliasStr = self::nativePath((string) $alias);
        $targetStr = self::nativePath((string) $target);

        // Stat cache invalidation: when the previous call to `link()`
        // (or anything else in the same process) probed `$aliasStr` and
        // got "does not exist", a subsequent `mklink /J` invoked
        // out-of-process leaves PHP's stat cache stale. Without clearing,
        // `is_link` / `is_dir` checks below would still report the
        // pre-creation state on the second call.
        \clearstatcache(true, $aliasStr);
        \clearstatcache(true, $targetStr);

        // The target may legitimately not exist yet in dry-run mode (the
        // copy phase did not create it because we are not writing). Fall
        // back to the literal path so the state matrix can still
        // report collisions — the only thing it really needs the target
        // path for is the "link already points at $target?" comparison.
        $resolved = \realpath($targetStr);
        if ($resolved === false) {
            if (!$dryRun) {
                return LinkResult::failed(
                    $alias,
                    $target,
                    'target directory does not exist: ' . $targetStr,
                );
            }
            $resolvedTarget = $targetStr;
        } else {
            $resolvedTarget = $resolved;
        }

        if (\file_exists($aliasStr) || \is_link($aliasStr)) {
            return $this->handleExisting($alias, $target, $aliasStr, $resolvedTarget);
        }

        // Windows-only: cross-volume junction is impossible. Detect by
        // comparing drive prefixes (or UNC roots) before touching the FS.
        if (self::isWindows() && self::crossVolume($aliasStr, $resolvedTarget)) {
            return LinkResult::failed(
                $alias,
                $target,
                'cross-volume junctions are not supported on Windows '
                . '(target is on a different drive or share)',
            );
        }

        if ($dryRun) {
            return LinkResult::wouldCreate($alias, $target);
        }

        $parentCreated = $this->ensureParent($aliasStr);
        if ($parentCreated !== null) {
            return LinkResult::failed($alias, $target, $parentCreated);
        }

        $createError = $this->createLink($aliasStr, $resolvedTarget);
        if ($createError !== null) {
            return LinkResult::failed($alias, $target, $createError);
        }

        return LinkResult::created($alias, $target);
    }

    /**
     * Cross-volume detection for Windows. Compares the drive letter
     * (or UNC root) of two normalised paths. Used before `mklink /J`,
     * since junctions cannot cross volumes.
     *
     * @psalm-pure
     */
    private static function crossVolume(string $aliasPath, string $targetPath): bool
    {
        $aliasVolume = self::volumeOf($aliasPath);
        $targetVolume = self::volumeOf($targetPath);
        if ($aliasVolume === null || $targetVolume === null) {
            return false;
        }

        return \strcasecmp($aliasVolume, $targetVolume) !== 0;
    }

    /**
     * Extract the volume prefix of an absolute Windows path: a drive
     * letter (`C:`) or a UNC share root (`\\server\share`). Returns
     * `null` for paths that have neither (relative paths, POSIX
     * absolutes that should never reach this code on Windows).
     *
     * @return non-empty-string|null
     *
     * @psalm-pure
     */
    private static function volumeOf(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $normalised = \str_replace('/', '\\', $path);

        if (\preg_match('~^([a-zA-Z]:)~', $normalised, $m) === 1) {
            $drive = $m[1];
            return $drive === '' ? null : $drive;
        }

        if (\str_starts_with($normalised, '\\\\')) {
            // UNC: \\server\share — first two segments after the leading \\.
            $rest = \substr($normalised, 2);
            $parts = \explode('\\', $rest);
            if (\count($parts) >= 2 && $parts[0] !== '' && $parts[1] !== '') {
                return '\\\\' . $parts[0] . '\\' . $parts[1];
            }
        }

        return null;
    }

    /**
     * @psalm-pure
     */
    private static function pathsEqual(string $a, string $b): bool
    {
        if (self::isWindows()) {
            return \strcasecmp(
                \str_replace('/', '\\', \rtrim($a, '/\\')),
                \str_replace('/', '\\', \rtrim($b, '/\\')),
            ) === 0;
        }

        return \rtrim($a, '/') === \rtrim($b, '/');
    }

    /**
     * @psalm-pure
     */
    private static function isWindows(): bool
    {
        return \DIRECTORY_SEPARATOR === '\\';
    }

    /**
     * Convert a forward-slash-normalised path to native separators.
     * No-op on POSIX where `DIRECTORY_SEPARATOR === '/'` already.
     *
     * @psalm-pure
     */
    private static function nativePath(string $path): string
    {
        return self::isWindows()
            ? \str_replace('/', '\\', $path)
            : $path;
    }

    /**
     * Detect an NTFS junction (or any other "directory-shaped link"
     * whose realpath escapes the parent). Called only when `is_link()`
     * has already returned false and `is_dir()` true, so this is the
     * fallback path that distinguishes "junction" from "plain
     * directory" on Windows.
     */
    private static function isJunction(string $path): bool
    {
        $resolved = \realpath($path);
        $parentResolved = \realpath(\dirname($path));
        if ($resolved === false || $parentResolved === false) {
            return false;
        }

        $expected = $parentResolved . \DIRECTORY_SEPARATOR . \basename($path);

        return self::isWindows()
            ? \strcasecmp($resolved, $expected) !== 0
            : $resolved !== $expected;
    }

    /**
     * The alias path already exists — figure out whether it is an
     * acceptable no-op (link → target) or a fatal collision. The
     * state matrix is identical between dry-run and normal modes;
     * collisions block creation either way.
     */
    private function handleExisting(
        Path $alias,
        Path $target,
        string $aliasStr,
        string $resolvedTarget,
    ): LinkResult {
        // Windows NTFS junctions are a third state PHP cannot detect via
        // `is_link()` *or* `is_dir()` reliably in every build (some report
        // is_dir=true, some false; readlink results vary too). The robust
        // signal is realpath escaping the parent dir — see {@see isJunction()}.
        // Test the junction case before is_dir so a junction-shaped alias
        // is never mis-classified as a "real directory" and rejected.
        if (\is_link($aliasStr) || self::isJunction($aliasStr)) {
            return $this->compareLinkTarget($alias, $target, $aliasStr, $resolvedTarget);
        }

        if (\is_dir($aliasStr)) {
            return LinkResult::failed(
                $alias,
                $target,
                'a real directory already exists at the alias path; refusing to replace it',
            );
        }

        return LinkResult::failed(
            $alias,
            $target,
            'a regular file already exists at the alias path',
        );
    }

    /**
     * Compare the resolved target of an existing link with the
     * expected one. Equal → no-op success; different → fatal.
     */
    private function compareLinkTarget(
        Path $alias,
        Path $target,
        string $aliasStr,
        string $resolvedTarget,
    ): LinkResult {
        $resolvedExisting = \realpath($aliasStr);
        if ($resolvedExisting === false) {
            return LinkResult::failed(
                $alias,
                $target,
                'existing link at alias path cannot be resolved (broken link?)',
            );
        }

        if (self::pathsEqual($resolvedExisting, $resolvedTarget)) {
            return LinkResult::alreadyCorrect($alias, $target);
        }

        return LinkResult::failed(
            $alias,
            $target,
            \sprintf(
                'existing link points elsewhere (%s); refusing to overwrite',
                $resolvedExisting,
            ),
        );
    }

    /**
     * Ensure the parent directory of `$aliasStr` exists. Returns `null`
     * on success or an error message on failure.
     *
     * @return non-empty-string|null
     */
    private function ensureParent(string $aliasStr): ?string
    {
        $parent = \dirname($aliasStr);
        if ($parent === '' || $parent === '.' || \is_dir($parent)) {
            return null;
        }
        if (!@\mkdir($parent, recursive: true) && !\is_dir($parent)) {
            return 'failed to create parent directory: ' . $parent;
        }

        return null;
    }

    /**
     * @return non-empty-string|null error message on failure, `null` on success
     */
    private function createLink(string $aliasStr, string $resolvedTarget): ?string
    {
        if (self::isWindows()) {
            return $this->createJunction($aliasStr, $resolvedTarget);
        }

        if (!@\symlink($resolvedTarget, $aliasStr)) {
            $err = \error_get_last()['message'] ?? 'unknown error';
            return 'symlink() failed: ' . $err;
        }

        return null;
    }

    /**
     * @return non-empty-string|null
     */
    private function createJunction(string $aliasStr, string $resolvedTarget): ?string
    {
        $aliasNative = \str_replace('/', '\\', $aliasStr);
        $targetNative = \str_replace('/', '\\', $resolvedTarget);

        $cmd = \sprintf(
            'mklink /J %s %s',
            \escapeshellarg($aliasNative),
            \escapeshellarg($targetNative),
        );

        $rawOutput = [];
        $exit = -1;
        // `mklink` is a cmd.exe builtin, so it must be invoked via cmd /c.
        @\exec('cmd /c ' . $cmd . ' 2>&1', $rawOutput, $exit);

        if ($exit !== 0) {
            $lines = [];
            /** @var mixed $line */
            foreach ($rawOutput as $line) {
                if (\is_string($line) && $line !== '') {
                    $lines[] = $line;
                }
            }
            $tail = $lines === [] ? 'no output' : \implode(' ', $lines);
            return 'mklink /J failed: ' . $tail;
        }

        return null;
    }
}
