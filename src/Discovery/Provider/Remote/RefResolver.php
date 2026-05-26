<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote;

/**
 * Pure helpers for the ref-resolution rules used by remote adapters.
 *
 * Three responsibilities:
 *
 * - **Classify tags.** Detect semver-shaped tags (`X.Y.Z` /
 *   `vX.Y.Z`) and stable-vs-prerelease (presence of a `-suffix`
 *   like `-rc.1` / `-beta` / `-alpha`).
 * - **Pick the best tag** from a list — highest stable, falling
 *   back to highest semver overall, falling back to the default
 *   branch HEAD (the cascade).
 * - **Apply caret constraints** — match `^X.Y.Z` (the only
 *   constraint flavour supported) against a tag list.
 *
 * Composer ships a full semver implementation in
 * `composer/semver`, but the resolver here intentionally rolls a
 * narrow subset: only the pieces this plugin actually requires, no
 * `*` / `~` / `>=` / `<` / `||` parsing, no per-major caret
 * edge cases below 1.0.0. Keeping this minimal means the resolver
 * stays pure, testable from a fixture list of tag strings, and
 * impossible to misuse by passing weird Composer constraints
 * we do not promise to support.
 *
 * @psalm-immutable
 */
final readonly class RefResolver
{
    /**
     * Tags shaped like `X.Y.Z` or `vX.Y.Z` with no suffix. The
     * three-component requirement is deliberate: `v1` / `v1.0`
     * tags are not "stable semver" by this definition — they would
     * make caret formation in `skills:add` ambiguous (is `^1`
     * shorthand for `^1.0.0`?). Caret formation is tied to
     * three-component tags only.
     */
    private const STABLE_TAG_REGEX = '/^v?(\d+)\.(\d+)\.(\d+)$/';

    /** Any semver-shape, including prereleases. Used by the cascade's fallback step. */
    private const ANY_SEMVER_TAG_REGEX = '/^v?(\d+)\.(\d+)\.(\d+)(?:-[\w.+-]+)?$/';

    /** Caret constraint: `^A`, `^A.B`, or `^A.B.C`. */
    private const CARET_CONSTRAINT_REGEX = '/^\^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/';

    /**
     * Whether `$tag` is a "stable semver" tag (three-component,
     * no prerelease suffix).
     *
     * @psalm-pure
     */
    public function isStable(string $tag): bool
    {
        return \preg_match(self::STABLE_TAG_REGEX, $tag) === 1;
    }

    /**
     * Whether `$tag` is semver-shaped at all — stable OR prerelease.
     * Used by the cascade's "highest non-stable" step.
     *
     * @psalm-pure
     */
    public function isSemver(string $tag): bool
    {
        return \preg_match(self::ANY_SEMVER_TAG_REGEX, $tag) === 1;
    }

    /**
     * Highest stable tag in the list, or null when none exists.
     * "Highest" follows semver ordering on the (major, minor, patch)
     * triplet; the optional `v` prefix is ignored for comparison
     * but preserved verbatim in the returned string.
     *
     * @param list<non-empty-string> $tags
     *
     * @return non-empty-string|null
     *
     * @psalm-pure
     */
    public function pickHighestStable(array $tags): ?string
    {
        $best = null;
        $bestParts = null;
        foreach ($tags as $tag) {
            $parts = self::parseStable($tag);
            if ($parts === null) {
                continue;
            }
            if ($bestParts === null || self::compareParts($parts, $bestParts) > 0) {
                $best = $tag;
                $bestParts = $parts;
            }
        }
        return $best;
    }

    /**
     * Highest semver-shaped tag overall, including prereleases.
     * Comparison rule: stable > prerelease, then triplet order.
     * Prerelease suffixes are compared lexically as a tie-breaker
     * (sufficient for picking "newest non-stable" — full SemVer
     * 11 prerelease comparison is overkill for this use case).
     *
     * @param list<non-empty-string> $tags
     *
     * @return non-empty-string|null
     *
     * @psalm-pure
     */
    public function pickHighestAny(array $tags): ?string
    {
        $best = null;
        /** @var array{int, int, int, string}|null $bestParts */
        $bestParts = null;
        foreach ($tags as $tag) {
            $parts = self::parseAny($tag);
            if ($parts === null) {
                continue;
            }
            if ($bestParts === null || self::compareAnyParts($parts, $bestParts) > 0) {
                $best = $tag;
                $bestParts = $parts;
            }
        }
        return $best;
    }

    /**
     * Resolve a caret constraint against a tag list. Returns the
     * highest stable tag that satisfies the constraint, or null
     * when none does.
     *
     * Constraint shapes:
     *
     * - `^1.2.3` → `>= 1.2.3, < 2.0.0`
     * - `^1.2`   → `>= 1.2.0, < 2.0.0`
     * - `^1`     → `>= 1.0.0, < 2.0.0`
     * - `^v1.2.3` is treated identically to `^1.2.3`
     *
     * Pre-1.0 caret semantics (`^0.2.3` meaning `< 0.3.0`) are
     * intentionally not implemented — caret support is only
     * promised for major>=1. Pre-1.0 inputs return null rather
     * than guessing.
     *
     * @param list<non-empty-string> $tags
     *
     * @return non-empty-string|null
     *
     * @psalm-pure
     */
    public function resolveCaret(string $constraint, array $tags): ?string
    {
        $match = \preg_match(self::CARET_CONSTRAINT_REGEX, $constraint, $m);
        if ($match !== 1) {
            return null;
        }
        $major = (int) $m[1];
        $minor = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $patch = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;

        if ($major < 1) {
            // Pre-1.0 caret semantics differ — out of scope.
            return null;
        }

        $floor = [$major, $minor, $patch];
        $ceiling = [$major + 1, 0, 0];

        $best = null;
        /** @var array{int, int, int}|null $bestParts */
        $bestParts = null;
        foreach ($tags as $tag) {
            $parts = self::parseStable($tag);
            if ($parts === null) {
                continue;
            }
            if (self::compareParts($parts, $floor) < 0) {
                continue;
            }
            if (self::compareParts($parts, $ceiling) >= 0) {
                continue;
            }
            if ($bestParts === null || self::compareParts($parts, $bestParts) > 0) {
                $best = $tag;
                $bestParts = $parts;
            }
        }

        return $best;
    }

    /**
     * Format a stable tag as a `^X.Y.Z` constraint for storage in
     * `skills.json`. Strips an optional leading `v` to keep the
     * constraint shape canonical — Composer-style constraints
     * don't carry the prefix.
     *
     * Returns `null` if the input is not a stable tag, signalling
     * the caller that no auto-caret can be derived (cascade falls
     * back to omitting the `ref` field).
     *
     * @return non-empty-string|null
     *
     * @psalm-pure
     */
    public function formatCaret(string $stableTag): ?string
    {
        $parts = self::parseStable($stableTag);
        if ($parts === null) {
            return null;
        }
        return \sprintf('^%d.%d.%d', $parts[0], $parts[1], $parts[2]);
    }

    /**
     * Whether `$ref` looks like a caret constraint
     * (`^1.2.3` / `^1` / `^v1.2.3`). Used by the adapter to
     * decide between "treat as literal tag/branch" and "resolve
     * via tag listing".
     *
     * @psalm-pure
     */
    public function isCaretConstraint(string $ref): bool
    {
        return \preg_match(self::CARET_CONSTRAINT_REGEX, $ref) === 1;
    }

    /**
     * @return array{int, int, int}|null
     *
     * @psalm-pure
     */
    private static function parseStable(string $tag): ?array
    {
        $match = \preg_match(self::STABLE_TAG_REGEX, $tag, $m);
        if ($match !== 1) {
            return null;
        }
        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    /**
     * @return array{int, int, int, string}|null prerelease suffix is `''` for stable tags
     *
     * @psalm-pure
     */
    private static function parseAny(string $tag): ?array
    {
        $match = \preg_match(self::ANY_SEMVER_TAG_REGEX, $tag, $m);
        if ($match !== 1) {
            return null;
        }
        $prerelease = '';
        $dashPos = \strpos($tag, '-');
        if ($dashPos !== false) {
            $prerelease = \substr($tag, $dashPos + 1);
        }
        return [(int) $m[1], (int) $m[2], (int) $m[3], $prerelease];
    }

    /**
     * @param array{int, int, int} $a
     * @param array{int, int, int} $b
     *
     * @psalm-pure
     */
    private static function compareParts(array $a, array $b): int
    {
        return $a[0] <=> $b[0] ?: $a[1] <=> $b[1] ?: $a[2] <=> $b[2];
    }

    /**
     * @param array{int, int, int, string} $a
     * @param array{int, int, int, string} $b
     *
     * @psalm-pure
     */
    private static function compareAnyParts(array $a, array $b): int
    {
        $core = $a[0] <=> $b[0] ?: $a[1] <=> $b[1] ?: $a[2] <=> $b[2];
        if ($core !== 0) {
            return $core;
        }
        // Stable (empty prerelease) outranks any prerelease — the
        // standard SemVer precedence rule. Among prereleases we fall
        // back to lexical ordering as a "good enough" tiebreaker;
        // full dotted-identifier comparison is not needed here.
        if ($a[3] === '' && $b[3] !== '') {
            return 1;
        }
        if ($a[3] !== '' && $b[3] === '') {
            return -1;
        }
        return \strcmp($a[3], $b[3]);
    }
}
