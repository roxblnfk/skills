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
 * - **POSIX**: a {@see \symlink()} with a *relative* target, so the
 *   alias survives the project being mounted or moved elsewhere (e.g.
 *   synced inside a container, used on the host).
 *
 * State matrix — applied to the alias path *before* creating
 * anything:
 *
 * | Existing state                               | Action                          |
 * |---------------------------------------------|---------------------------------|
 * | Does not exist                              | Create the link.                |
 * | Junction → target, relative symlink → target| No-op, success.                 |
 * | Absolute symlink → target (POSIX)           | Rewrite as relative.            |
 * | Symlink/junction → elsewhere, or dangling   | Replace.                        |
 * | Real directory                              | Failed (would destroy content). |
 * | Regular file                                | Failed.                         |
 *
 * The alias path belongs to the plugin once it is configured, so a link
 * there that does not reach the target is re-pointed: typically one left
 * by a copy of the project, or by a sync under another mount point. A
 * link holds no content, so replacing it loses nothing.
 *
 * No "force" mode for real content: to replace a real directory with an
 * alias the user removes the directory themselves and re-runs.
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
     *         writes; a would-be-created alias is reported as {@see LinkStatus::WouldCreate},
     *         a would-be-rewritten one as {@see LinkStatus::WouldReplace}
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
            return $this->handleExisting($alias, $target, $aliasStr, $resolvedTarget, $dryRun);
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

        $createError = $this->createLink($alias, $target, $aliasStr, $resolvedTarget);
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
     * The relative symlink value that makes `$alias` reach the target.
     *
     * The kernel resolves a relative link from the directory the link
     * physically sits in, so a candidate is accepted only once it
     * resolves to `$resolvedTarget` from there. The first candidate keeps
     * the logical path — and with it a symlinked target such as
     * `.agents/skills -> /data/skills` — and the others step down to
     * physical paths for a symlinked alias parent. When no candidate
     * can be verified — an unreadable directory on the way — the
     * absolute `$resolvedTarget` is used, which at least works here.
     */
    private static function relativeTarget(Path $alias, Path $target, string $resolvedTarget): string
    {
        $parentReal = \realpath((string) $alias->parent());
        if ($parentReal === false) {
            return $resolvedTarget;
        }

        $parent = Path::create($parentReal);
        $candidates = [
            self::relativePath($target, $alias->parent()),
            self::relativePath($target, $parent),
            self::relativePath(Path::create($resolvedTarget), $parent),
        ];
        foreach ($candidates as $candidate) {
            try {
                $reached = \realpath((string) $parent->join($candidate));
            } catch (\LogicException) {
                // The candidate climbs above `/` from the physical parent.
                continue;
            }
            if ($reached !== false && self::pathsEqual($reached, $resolvedTarget)) {
                return $candidate;
            }
        }

        return $resolvedTarget;
    }

    /**
     * `$path` relative to `$base`; both are absolute, and on POSIX they
     * always share the `/` root, so a relative form always exists.
     */
    private static function relativePath(Path $path, Path $base): string
    {
        return (string) ($path->tryRelative($base) ?? $path);
    }

    /**
     * Whether the stored symlink value names the target path. Lets a dry
     * run recognise a link whose target the copy phase has not created
     * yet. A relative value is read from the alias's physical directory,
     * as the kernel reads it, and compared with the target both as
     * configured and with its existing parent resolved. A value
     * {@see Path} cannot normalise, such as `/../x`, names nothing.
     */
    private static function storedPointsAt(string $stored, Path $alias, Path $target): bool
    {
        $aliasParent = \realpath((string) $alias->parent());
        try {
            $named = (string) match (true) {
                \str_starts_with($stored, '/') => Path::create($stored),
                $aliasParent !== false => Path::create($aliasParent)->join($stored),
                default => $alias->parent()->join($stored),
            };
        } catch (\LogicException) {
            return false;
        }

        $targetParent = \realpath((string) $target->parent());

        return self::pathsEqual($named, (string) $target)
            || ($targetParent !== false
                && self::pathsEqual($named, (string) Path::create($targetParent)->join($target->name())));
    }

    /**
     * @return non-empty-string|null error message on failure, `null` on success
     */
    private static function symlinkAt(string $value, string $at): ?string
    {
        if (!@\symlink($value, $at)) {
            $err = \error_get_last()['message'] ?? 'unknown error';
            return 'symlink() failed: ' . $err;
        }

        return null;
    }

    /**
     * The alias path already exists — figure out whether it is an
     * acceptable no-op (link → target), a link to replace, or a fatal
     * collision with real content. The state matrix is identical between
     * dry-run and normal modes; collisions block creation either way.
     */
    private function handleExisting(
        Path $alias,
        Path $target,
        string $aliasStr,
        string $resolvedTarget,
        bool $dryRun,
    ): LinkResult {
        // Windows NTFS junctions are a third state PHP cannot detect via
        // `is_link()` *or* `is_dir()` reliably in every build (some report
        // is_dir=true, some false; readlink results vary too). The robust
        // signal is realpath escaping the parent dir — see {@see isJunction()}.
        // Test the junction case before is_dir so a junction-shaped alias
        // is never mis-classified as a "real directory" and rejected.
        if (\is_link($aliasStr) || self::isJunction($aliasStr)) {
            return $this->compareLinkTarget($alias, $target, $aliasStr, $resolvedTarget, $dryRun);
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
     * expected one. Equal → no-op success, or a rewrite when a POSIX
     * symlink stores it as an absolute path; elsewhere or dangling →
     * the link is replaced.
     */
    private function compareLinkTarget(
        Path $alias,
        Path $target,
        string $aliasStr,
        string $resolvedTarget,
        bool $dryRun,
    ): LinkResult {
        $stored = (!self::isWindows() && \is_link($aliasStr) ? \readlink($aliasStr) : false) ?: null;

        $resolvedExisting = \realpath($aliasStr);
        // A dry run before the copy phase leaves the target missing, so a
        // correct link dangles; the stored value is all there is to check.
        if (
            $resolvedExisting === false
            && $stored !== null
            && \realpath($resolvedTarget) === false
            && self::storedPointsAt($stored, $alias, $target)
        ) {
            $resolvedExisting = $resolvedTarget;
        }

        if ($resolvedExisting !== false && self::pathsEqual($resolvedExisting, $resolvedTarget)) {
            return $stored !== null && \str_starts_with($stored, '/')
                ? $this->replaceLink($alias, $target, $resolvedTarget, $stored, $dryRun, working: true)
                : LinkResult::alreadyCorrect($alias, $target);
        }

        if (self::isWindows()) {
            $previous = @\readlink($aliasStr);
            $previous = match (true) {
                $previous !== false && $previous !== '' => $previous,
                $resolvedExisting !== false && $resolvedExisting !== '' => $resolvedExisting,
                default => 'an unresolvable junction',
            };

            return $this->replaceJunction($alias, $target, $aliasStr, $resolvedTarget, $previous, $dryRun);
        }

        return $this->replaceLink(
            $alias,
            $target,
            $resolvedTarget,
            $stored ?? 'an unreadable link',
            $dryRun,
            working: false,
        );
    }

    /**
     * Swap the junction at `$aliasStr` for one pointing at the target.
     * Junctions cannot be renamed over one another, so the old one is
     * removed first; `rmdir()` strips a junction without touching what
     * it points at.
     *
     * @param non-empty-string $previous what the junction pointed at, for the report
     */
    private function replaceJunction(
        Path $alias,
        Path $target,
        string $aliasStr,
        string $resolvedTarget,
        string $previous,
        bool $dryRun,
    ): LinkResult {
        if (self::crossVolume($aliasStr, $resolvedTarget)) {
            return LinkResult::failed(
                $alias,
                $target,
                'cross-volume junctions are not supported on Windows '
                . '(target is on a different drive or share)',
            );
        }

        if ($dryRun) {
            return LinkResult::wouldReplace($alias, $target, $previous);
        }

        if (!@\rmdir($aliasStr)) {
            $err = \error_get_last()['message'] ?? 'unknown error';
            return LinkResult::failed($alias, $target, 'failed to remove the existing junction: ' . $err);
        }

        $createError = $this->createJunction($aliasStr, $resolvedTarget);

        return $createError === null
            ? LinkResult::replaced($alias, $target, $previous)
            : LinkResult::failed($alias, $target, 'the old junction was removed, but ' . $createError);
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
    private function createLink(Path $alias, Path $target, string $aliasStr, string $resolvedTarget): ?string
    {
        if (self::isWindows()) {
            return $this->createJunction($aliasStr, $resolvedTarget);
        }

        return self::symlinkAt(self::relativeTarget($alias, $target, $resolvedTarget), $aliasStr);
    }

    /**
     * Rewrite the POSIX symlink at `$alias` to the relative form.
     *
     * @param non-empty-string $stored the value the symlink holds now
     * @param bool $working the link already reaches the target; a rewrite that cannot happen
     *         (read-only directory, no verifiable relative form) leaves it as already correct
     *         rather than failing the run
     */
    private function replaceLink(
        Path $alias,
        Path $target,
        string $resolvedTarget,
        string $stored,
        bool $dryRun,
        bool $working,
    ): LinkResult {
        if ($dryRun) {
            return LinkResult::wouldReplace($alias, $target, $stored);
        }

        $value = self::relativeTarget($alias, $target, $resolvedTarget);
        if ($value === $stored) {
            return LinkResult::alreadyCorrect($alias, $target);
        }

        // A fresh link renamed over the old one: rename(2) swaps the alias
        // atomically, so no moment exists where the alias path is missing.
        $temp = (string) $alias->parent()->join('.' . $alias->name() . '.' . \uniqid('', true) . '.tmp');
        $error = self::symlinkAt($value, $temp);
        if ($error === null && !@\rename($temp, (string) $alias)) {
            $error = 'failed to replace the existing link: ' . (\error_get_last()['message'] ?? 'unknown error');
            @\unlink($temp);
        }

        return match (true) {
            $error === null => LinkResult::replaced($alias, $target, $stored),
            $working => LinkResult::alreadyCorrect($alias, $target),
            default => LinkResult::failed($alias, $target, $error),
        };
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
