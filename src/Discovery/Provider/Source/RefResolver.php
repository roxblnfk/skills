<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Source;

/**
 * Pure helpers for the ref-resolution rules used by remote adapters.
 *
 * Four responsibilities:
 *
 * - **Classify tags.** Detect semver-shaped tags (`X.Y.Z` /
 *   `vX.Y.Z`) and stable-vs-prerelease (presence of a `-suffix`
 *   like `-rc.1` / `-beta` / `-alpha`).
 * - **Pick the best tag** from a list — highest stable, falling
 *   back to highest semver overall, falling back to the default
 *   branch HEAD (the cascade).
 * - **Apply constraints** — match a caret (`^X.Y.Z`) or tilde
 *   (`~X.Y.Z`) range, or an exact version (`=X.Y.Z`), against a tag
 *   list. The exact form matches by normalized equality, so `=1.4.2`
 *   finds `v1.4.2` and `1.4.2` alike — this is what `self.version`
 *   refs in vendor-declared sources compile down to.
 * - **Reject what it cannot do** — {@see self::looksLikeConstraint()}
 *   flags constraint syntax outside that set, so the config layer can
 *   answer "unsupported constraint" instead of letting the string reach
 *   the wire as a literal tag name and come back a 404.
 *
 * Composer ships a full semver implementation in `composer/semver`,
 * but the resolver here intentionally rolls a narrow subset: caret,
 * tilde and exact, no `*` / `>=` / `<` / `||` / hyphen-range /
 * stability-flag parsing. Both supported operators follow Composer's own rules —
 * caret honours the pre-1.0 special case (`^0.y.z` locks the minor),
 * so a version `skills:add` pins on a 0.x donor resolves the way
 * Composer would; tilde has no such special case, so `~0.2` allows the
 * whole `0.x` line. Keeping the rest out means the resolver stays pure
 * and testable from a fixture list of tag strings, and the gap is
 * visible rather than silent: anything unimplemented is reported as an
 * unsupported constraint rather than mistaken for a tag.
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

    /**
     * Range constraint: a `^` or `~` operator followed by one to
     * three numeric components. The operator is captured so
     * {@see self::resolveConstraint()} can pick the matching ceiling
     * rule without re-parsing the string.
     */
    private const CONSTRAINT_REGEX = '/^([\^~])v?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/';

    /**
     * Exact-version constraint: `=` followed by one to four numeric
     * components and an optional prerelease suffix (`=1.4.2`,
     * `=v1.4`, `=2.0.0-beta1`). Matching is by normalized equality —
     * the `v` prefix is ignored, missing components count as zero, and
     * trailing zero components collapse — so the constraint finds the
     * tag regardless of the repository's prefix convention.
     */
    private const EXACT_CONSTRAINT_REGEX = '/^=v?\d+(?:\.\d+){0,3}(?:-[\w.+-]+)?$/';

    /**
     * Syntax that is unmistakably a version constraint rather than a
     * tag or branch name. Used by {@see self::looksLikeConstraint()}
     * to separate "the user meant a constraint we don't support" from
     * "the user meant a literal ref".
     *
     * Deliberately narrow — a ref like `release-1.2` or `feature/x`
     * must not trip it. Only wildcards, comparison operators, and the
     * multi-constraint separators qualify; a leading `^`/`~` counts
     * too, since a caret or tilde that failed
     * {@see self::CONSTRAINT_REGEX} is a malformed constraint, never a
     * plausible git ref.
     */
    private const CONSTRAINT_SYNTAX_REGEX = '/^[\^~><=!]|[*|,]|\s-\s|\s/';

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
     * Resolve a caret, tilde, or exact constraint against a tag list.
     * Returns the highest stable tag that satisfies the constraint, or
     * null when none does (including when `$constraint` is not a
     * constraint at all).
     *
     * Exact — `=` followed by a version; matches the first tag whose
     * normalized form (`v` prefix ignored, missing components counted
     * as zero) equals the version, so `=1.4.2` finds `v1.4.2`,
     * `1.4.2`, and `=1.4` finds `v1.4.0`. Prerelease suffixes must
     * match verbatim.
     *
     * Caret — bump the left-most non-zero component the constraint
     * specified:
     *
     * - `^1.2.3` → `>= 1.2.3, < 2.0.0`
     * - `^1.2`   → `>= 1.2.0, < 2.0.0`
     * - `^1`     → `>= 1.0.0, < 2.0.0`
     * - `^0.2.3` → `>= 0.2.3, < 0.3.0`  (0.x locks the minor)
     * - `^0.0.3` → `>= 0.0.3, < 0.0.4`  (0.0.x locks the patch)
     *
     * Tilde — bump the *last component the constraint spelled out*,
     * with no pre-1.0 special case:
     *
     * - `~1.2.3` → `>= 1.2.3, < 1.3.0`  (patch is free)
     * - `~1.2`   → `>= 1.2.0, < 2.0.0`  (minor is free)
     * - `~1`     → `>= 1.0.0, < 2.0.0`
     * - `~0.2.3` → `>= 0.2.3, < 0.3.0`
     * - `~0.2`   → `>= 0.2.0, < 1.0.0`  (the whole 0.x line)
     *
     * A `v` prefix on the constraint is accepted and ignored, so
     * `^v1.2.3` behaves identically to `^1.2.3`.
     *
     * See {@see self::caretCeiling()} and {@see self::tildeCeiling()}
     * for the exact upper-bound rules.
     *
     * @param list<non-empty-string> $tags
     *
     * @return non-empty-string|null
     *
     * @psalm-pure
     */
    public function resolveConstraint(string $constraint, array $tags): ?string
    {
        if (\preg_match(self::EXACT_CONSTRAINT_REGEX, $constraint) === 1) {
            return self::resolveExact(\substr($constraint, 1), $tags);
        }

        $match = \preg_match(self::CONSTRAINT_REGEX, $constraint, $m);
        if ($match !== 1) {
            return null;
        }
        $operator = $m[1];
        $minorGiven = isset($m[3]) && $m[3] !== '';
        $patchGiven = isset($m[4]) && $m[4] !== '';
        $major = (int) $m[2];
        $minor = $minorGiven ? (int) $m[3] : 0;
        $patch = $patchGiven ? (int) $m[4] : 0;

        $floor = [$major, $minor, $patch];
        $ceiling = $operator === '~'
            ? self::tildeCeiling($major, $minor, $patchGiven)
            : self::caretCeiling($major, $minor, $patch, $minorGiven, $patchGiven);

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
     * Whether `$ref` is a constraint this resolver can satisfy
     * (`^1.2.3` / `~1.2` / `^v1`). Used by the adapters to decide
     * between "treat as literal tag/branch" and "resolve via tag
     * listing".
     *
     * @psalm-pure
     */
    public function isConstraint(string $ref): bool
    {
        return \preg_match(self::CONSTRAINT_REGEX, $ref) === 1
            || \preg_match(self::EXACT_CONSTRAINT_REGEX, $ref) === 1;
    }

    /**
     * Whether `$ref` carries version-constraint syntax — regardless of
     * whether this resolver can satisfy it.
     *
     * The pair with {@see self::isConstraint()} is what lets the config
     * layer tell the two failure modes apart:
     *
     * - `looksLikeConstraint() && isConstraint()` → resolvable.
     * - `looksLikeConstraint() && !isConstraint()` → the user wrote a
     *   constraint flavour we do not implement (`1.*`, `>=1.0`,
     *   `^1 || ^2`, `~1.2.3.4`). Reject it at config-load time; passing
     *   it through would send the raw string to the host as a tag name
     *   and surface as an unexplained 404 later.
     * - `!looksLikeConstraint()` → a literal tag, branch, or SHA.
     *
     * @psalm-pure
     */
    public function looksLikeConstraint(string $ref): bool
    {
        return \preg_match(self::CONSTRAINT_SYNTAX_REGEX, $ref) === 1;
    }

    /**
     * First tag equal to `$version` under exact-match normalization,
     * or null when none is. The tag is returned verbatim (prefix
     * preserved) so the archive URL uses the spelling the host knows.
     *
     * @param list<non-empty-string> $tags
     *
     * @return non-empty-string|null
     *
     * @psalm-pure
     */
    private static function resolveExact(string $version, array $tags): ?string
    {
        $wanted = self::normaliseExact($version);
        foreach ($tags as $tag) {
            if (self::normaliseExact($tag) === $wanted) {
                return $tag;
            }
        }
        return null;
    }

    /**
     * Canonical spelling for exact-match comparison: the `v` prefix is
     * dropped, a purely numeric core is padded to three zero-trimmed
     * components (`1.4` → `1.4.0`), and a prerelease suffix rides along
     * verbatim. Non-numeric cores (branch-like strings) are returned
     * as-is minus the prefix, degrading to literal comparison.
     *
     * @psalm-pure
     */
    private static function normaliseExact(string $version): string
    {
        $bare = \preg_replace('/^v(?=\d)/', '', $version) ?? $version;

        $dash = \strpos($bare, '-');
        $core = $dash === false ? $bare : \substr($bare, 0, $dash);
        $suffix = $dash === false ? '' : \substr($bare, $dash);

        $parts = \explode('.', $core);
        foreach ($parts as $part) {
            if ($part === '' || !\ctype_digit($part)) {
                return $bare;
            }
        }
        while (\count($parts) < 3) {
            $parts[] = '0';
        }
        $parts = \array_map(static fn(string $p): string => (string) (int) $p, $parts);
        // A fourth (or later) all-zero component is noise Composer adds
        // in normalized versions — `1.2.3.0` and `1.2.3` are the same
        // release.
        while (\count($parts) > 3 && $parts[\count($parts) - 1] === '0') {
            \array_pop($parts);
        }

        return \implode('.', $parts) . $suffix;
    }

    /**
     * Exclusive upper bound for a caret constraint, following
     * Composer's rule: bump the left-most non-zero component among
     * those the constraint specified and zero everything to its
     * right. When every specified component is zero, bump the
     * least-significant specified component.
     *
     * - `^1.2.3` → `[2, 0, 0]`  (major is the left-most non-zero)
     * - `^0.2.3` → `[0, 3, 0]`  (minor locked once major is 0)
     * - `^0.0.3` → `[0, 0, 4]`  (patch locked once major+minor are 0)
     * - `^0.2`   → `[0, 3, 0]`
     * - `^0`     → `[1, 0, 0]`
     * - `^0.0`   → `[0, 1, 0]`
     *
     * @return array{int, int, int}
     *
     * @psalm-pure
     */
    private static function caretCeiling(
        int $major,
        int $minor,
        int $patch,
        bool $minorGiven,
        bool $patchGiven,
    ): array {
        if ($major !== 0) {
            return [$major + 1, 0, 0];
        }
        if ($minorGiven && $minor !== 0) {
            return [0, $minor + 1, 0];
        }
        if (!$minorGiven) {
            // `^0` allows the whole 0.x range.
            return [1, 0, 0];
        }
        if ($patchGiven && $patch !== 0) {
            return [0, 0, $patch + 1];
        }
        // All specified components are zero: `^0.0.0` locks the patch,
        // `^0.0` locks the minor.
        return $patchGiven ? [0, 0, 1] : [0, 1, 0];
    }

    /**
     * Exclusive upper bound for a tilde constraint: bump the last
     * component the constraint actually spelled out and zero
     * everything to its right. Unlike the caret, the tilde has no
     * pre-1.0 special case — the operator's meaning is positional,
     * so `~0.2` is as permissive within `0.x` as `~1.2` is within
     * `1.x`.
     *
     * - `~1.2.3` → `[1, 3, 0]`  (patch was last → minor bumps)
     * - `~1.2`   → `[2, 0, 0]`  (minor was last → major bumps)
     * - `~1`     → `[2, 0, 0]`
     * - `~0.2.3` → `[0, 3, 0]`
     * - `~0.2`   → `[1, 0, 0]`
     * - `~0`     → `[1, 0, 0]`
     *
     * @return array{int, int, int}
     *
     * @psalm-pure
     */
    private static function tildeCeiling(int $major, int $minor, bool $patchGiven): array
    {
        return $patchGiven ? [$major, $minor + 1, 0] : [$major + 1, 0, 0];
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
