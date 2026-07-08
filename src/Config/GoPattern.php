<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

/**
 * A trust pattern that matches Go module paths.
 *
 * Two shapes:
 * - `github.com/owner/mod` — exact module-path match.
 * - `github.com/owner/*`   — every module path under the `github.com/owner`
 *                            prefix.
 *
 * Wildcard semantics are prefix-based, not single-segment: `github.com/owner/*`
 * matches `github.com/owner/repo`, the submodule `github.com/owner/repo/tools`,
 * and the major-version path `github.com/owner/repo/v2` alike. Go encodes
 * submodules and `/vN` major versions as extra path segments, so an org-level
 * trust entry only stays useful if it reaches arbitrary depth under its
 * prefix; matching exactly one segment would silently drop trust for every
 * submodule and versioned import. The wildcard requires at least one segment
 * after the prefix — the bare prefix alone is not a real module path.
 *
 * Matching is literal and case-sensitive. Go module paths are case-sensitive
 * (the module proxy escapes upper-case letters, but the canonical path keeps
 * its case), so a byte-for-byte comparison is the honest rule.
 *
 * @psalm-immutable
 */
final readonly class GoPattern implements TrustPattern
{
    /**
     * @param non-empty-string $raw   original textual pattern, kept for diagnostics
     * @param non-empty-string $base  the module path (exact) or the prefix
     *        before `/*` (wildcard)
     * @param bool $wildcard whether `$base` is a prefix (`base/*`) rather than
     *        a full module path
     *
     * @psalm-mutation-free
     */
    private function __construct(
        public string $raw,
        public string $base,
        public bool $wildcard,
    ) {}

    /**
     * @param non-empty-string $pattern
     *
     * @throws \InvalidArgumentException when the pattern is empty once the
     *         optional `/*` suffix is removed, or when a `*` appears anywhere
     *         other than that trailing `/*` wildcard — an embedded `*` yields
     *         a module path that can never match.
     *
     * @psalm-pure
     */
    public static function fromString(string $pattern): self
    {
        if (\str_ends_with($pattern, '/*')) {
            $base = \substr($pattern, 0, -2);
            if ($base === '') {
                throw new \InvalidArgumentException(\sprintf(
                    'Invalid go pattern "%s": a wildcard needs a module-path prefix before "/*".',
                    $pattern,
                ));
            }
            // The prefix is a literal module path; a `*` inside it (`own*er/*`)
            // never matches a real module.
            if (\str_contains($base, '*')) {
                throw new \InvalidArgumentException(\sprintf(
                    'Invalid go pattern "%s": "*" is only allowed as the trailing "/*" wildcard.',
                    $pattern,
                ));
            }

            return new self(raw: $pattern, base: $base, wildcard: true);
        }

        if ($pattern === '*') {
            throw new \InvalidArgumentException(
                'Invalid go pattern "*": a bare wildcard is not allowed; give it a prefix ("owner/*").',
            );
        }
        // An exact module path is literal; a `*` anywhere in it (`*/mod`,
        // `owner/*/v2`) yields a path that can never match.
        if (\str_contains($pattern, '*')) {
            throw new \InvalidArgumentException(\sprintf(
                'Invalid go pattern "%s": "*" is only allowed as the trailing "/*" wildcard.',
                $pattern,
            ));
        }

        return new self(raw: $pattern, base: $pattern, wildcard: false);
    }

    /**
     * @psalm-mutation-free
     */
    #[\Override]
    public function raw(): string
    {
        return $this->raw;
    }

    /**
     * @param non-empty-string $packageName  full Go module path
     *
     * @psalm-mutation-free
     */
    #[\Override]
    public function matches(string $packageName): bool
    {
        if ($this->wildcard) {
            return \str_starts_with($packageName, $this->base . '/');
        }

        return $packageName === $this->base;
    }
}
