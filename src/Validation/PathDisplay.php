<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

/**
 * Lexical path handling for the validator, on plain forward-slash strings, and the
 * rendering of paths in findings: relative to the working directory when inside it,
 * absolute otherwise.
 *
 * {@see \Internal\Path} would lose what the validator reports on: it trims spaces off
 * segment names, rejects `..` above the root, and folds a UNC `//server` prefix to `/`.
 *
 * @internal
 */
final class PathDisplay
{
    /** Normalized absolute working directory. */
    private readonly string $cwd;

    /**
     * @param string $cwd absolute working directory
     *
     * @psalm-mutation-free
     */
    public function __construct(string $cwd)
    {
        $this->cwd = self::normalize($cwd);
    }

    /**
     * @psalm-pure
     */
    public static function isAbsolute(string $path): bool
    {
        $path = self::separators($path);

        return \str_starts_with($path, '/')
            || (\PHP_OS_FAMILY === 'Windows' && \preg_match('~^[A-Za-z]:/~', $path) === 1);
    }

    /**
     * Forward slashes, no `.` segments, `..` resolved; `..` above a root stays at the root.
     * Keeps a drive letter (upper-cased) and a UNC `//` prefix.
     *
     * @psalm-pure
     */
    public static function normalize(string $path): string
    {
        $path = self::separators($path);
        $prefix = '';
        if (\preg_match('~^([A-Za-z]:)/?~', $path, $m) === 1) {
            $prefix = \strtoupper($m[1]) . '/';
            $path = \substr($path, \strlen($m[0]));
        } elseif (\str_starts_with($path, '//')) {
            $prefix = '//';
            $path = \substr($path, 2);
        } elseif (\str_starts_with($path, '/')) {
            $prefix = '/';
            $path = \substr($path, 1);
        }
        $out = [];
        foreach (\explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($out !== [] && \end($out) !== '..') {
                    \array_pop($out);
                } elseif ($prefix === '') {
                    $out[] = '..';
                }
                continue;
            }
            $out[] = $segment;
        }
        $joined = $prefix . \implode('/', $out);

        return $joined === '' ? '.' : $joined;
    }

    /**
     * The last segment of a normalized path, byte for byte.
     *
     * @psalm-pure
     */
    public static function name(string $path): string
    {
        $slash = \strrpos($path, '/');

        return $slash === false ? $path : \substr($path, $slash + 1);
    }

    /**
     * The parent of a normalized absolute path; a root is its own parent. Unlike
     * `dirname()`, it keeps forward slashes, which Windows `dirname()` turns into `C:\`
     * at a drive root.
     *
     * @psalm-pure
     */
    public static function parent(string $path): string
    {
        $slash = \strrpos($path, '/');
        if ($slash === false) {
            return '.';
        }
        $parent = \substr($path, 0, $slash);

        return match (true) {
            $parent === '' => '/',
            \preg_match('~^[A-Za-z]:$~', $parent) === 1 => $parent . '/',
            $parent === '/' => '//',
            default => $parent,
        };
    }

    /**
     * Whether `$path` is `$dir` or lies under it; both absolute and normalized. Letter case
     * is ignored on Windows.
     *
     * @psalm-pure
     */
    public static function isWithin(string $path, string $dir): bool
    {
        $fold = \PHP_OS_FAMILY === 'Windows' ? \strtolower(...) : static fn(string $s): string => $s;

        return $fold($path) === $fold($dir) || \str_starts_with($fold($path), $fold(\rtrim($dir, '/') . '/'));
    }

    /**
     * `$path` made absolute against `$base`, or against the working directory when `$base`
     * is `null`, and normalized.
     *
     * @psalm-mutation-free
     */
    public function absolute(string $path, ?string $base = null): string
    {
        return self::normalize(self::isAbsolute($path) ? $path : ($base ?? $this->cwd) . '/' . $path);
    }

    /**
     * @param string $path absolute and normalized
     *
     * @psalm-mutation-free
     */
    public function show(string $path): string
    {
        if (self::isWithin($path, $this->cwd)) {
            return \strlen($path) === \strlen($this->cwd) ? '.' : \substr($path, \strlen(\rtrim($this->cwd, '/')) + 1);
        }

        return $path;
    }

    /**
     * Backslashes as forward slashes on Windows, where both separate directories; elsewhere
     * a backslash is part of a name.
     *
     * @psalm-pure
     */
    private static function separators(string $path): string
    {
        return \PHP_OS_FAMILY === 'Windows' ? \str_replace('\\', '/', $path) : $path;
    }
}
