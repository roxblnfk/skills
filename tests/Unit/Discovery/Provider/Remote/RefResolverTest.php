<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider\Remote;

use LLM\Skills\Discovery\Provider\Remote\RefResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RefResolver::class)]
final class RefResolverTest
{
    public function stableTagIsRecognised(): void
    {
        $r = new RefResolver();
        Assert::true($r->isStable('1.2.3'));
        Assert::true($r->isStable('v1.2.3'));
        Assert::true($r->isStable('0.0.1'));
    }

    public function prereleaseIsNotStable(): void
    {
        // Stable means three-component with no suffix.
        $r = new RefResolver();
        Assert::false($r->isStable('1.2.3-rc.1'));
        Assert::false($r->isStable('v1.2.3-beta'));
        Assert::false($r->isStable('1.2.3-dev.4'));
        Assert::false($r->isStable('1.2.3-alpha.1'));
    }

    public function nonSemverShapesAreNotStable(): void
    {
        // Two-component tags and branch-like names are not stable.
        $r = new RefResolver();
        Assert::false($r->isStable('1.2'));
        Assert::false($r->isStable('v1'));
        Assert::false($r->isStable('main'));
        Assert::false($r->isStable('feature/foo'));
    }

    public function semverIncludesPrereleases(): void
    {
        $r = new RefResolver();
        Assert::true($r->isSemver('1.2.3'));
        Assert::true($r->isSemver('v1.2.3-rc.1'));
        Assert::true($r->isSemver('1.2.3-beta.1+build.2'));
    }

    public function semverRejectsBranchLikeStrings(): void
    {
        $r = new RefResolver();
        Assert::false($r->isSemver('main'));
        Assert::false($r->isSemver('1.2'));
    }

    public function pickHighestStableIgnoresPrereleases(): void
    {
        // Even when a prerelease is "newer" numerically, the cascade's
        // first step prefers stable. Anything prerelease gets ignored
        // here and is picked up by `pickHighestAny` if no stable exists.
        $r = new RefResolver();
        $tags = ['v1.0.0', '2.0.0-rc.1', 'v1.2.3', '0.9.0'];

        Assert::same($r->pickHighestStable($tags), 'v1.2.3');
    }

    public function pickHighestStableReturnsNullWhenNoneStable(): void
    {
        $r = new RefResolver();

        Assert::same($r->pickHighestStable(['1.0.0-rc.1', 'v2.0.0-beta']), null);
    }

    public function pickHighestAnyPreferStableOverPrereleaseOfSameVersion(): void
    {
        // SemVer precedence rule: 1.0.0 > 1.0.0-rc.1.
        $r = new RefResolver();

        Assert::same($r->pickHighestAny(['1.0.0', '1.0.0-rc.1', '1.0.0-rc.2']), '1.0.0');
    }

    public function pickHighestAnyFallsBackToPrereleaseWhenOnlyPrereleasesExist(): void
    {
        $r = new RefResolver();

        Assert::same(
            $r->pickHighestAny(['1.0.0-alpha', '1.0.0-rc.1', '1.0.0-beta']),
            '1.0.0-rc.1',
        );
    }

    public function pickHighestPreservesVPrefixVerbatim(): void
    {
        // The user may tag with or without `v`; the resolver picks
        // whichever the input string had — it doesn't normalise.
        $r = new RefResolver();

        Assert::same($r->pickHighestStable(['v1.0.0', '0.9.0']), 'v1.0.0');
        Assert::same($r->pickHighestStable(['1.5.0', 'v1.0.0']), '1.5.0');
    }

    public function caretResolvesHighestWithinSameMajor(): void
    {
        // ^1.2.3 means `>=1.2.3, <2.0.0`. Highest match wins.
        $r = new RefResolver();
        $tags = ['1.2.3', '1.2.4', '1.3.0', '1.9.9', '2.0.0', '0.9.0'];

        Assert::same($r->resolveCaret('^1.2.3', $tags), '1.9.9');
    }

    public function caretBelowFloorIsRejected(): void
    {
        $r = new RefResolver();
        // 1.2.2 is below floor 1.2.3 → not a match.
        Assert::same($r->resolveCaret('^1.2.3', ['1.2.2', '0.9.0']), null);
    }

    public function caretIgnoresPrereleases(): void
    {
        // Caret matching is reserved for stable tags. Prereleases
        // inside a valid range don't count toward a `^` match.
        $r = new RefResolver();

        Assert::same(
            $r->resolveCaret('^1.2.3', ['1.2.3-rc.1', '1.5.0-beta']),
            null,
        );
    }

    public function caretCrossingMajorBoundaryIsRejected(): void
    {
        $r = new RefResolver();

        Assert::same(
            $r->resolveCaret('^1.0.0', ['1.5.0', '2.0.0']),
            '1.5.0',
        );
    }

    public function caretWithoutPatchOrMinorIsParsed(): void
    {
        $r = new RefResolver();

        Assert::same($r->resolveCaret('^1', ['1.0.0', '1.5.0', '2.0.0']), '1.5.0');
        Assert::same($r->resolveCaret('^1.2', ['1.1.0', '1.2.0', '1.5.0']), '1.5.0');
    }

    public function caretTolerantToVPrefix(): void
    {
        $r = new RefResolver();

        // The `v` prefix in the constraint is normalised away.
        Assert::same($r->resolveCaret('^v1.2.0', ['v1.2.0', 'v1.5.0']), 'v1.5.0');
    }

    public function caretPre1LocksTheMinor(): void
    {
        // Composer's pre-1.0 rule: `^0.2.3` means `>=0.2.3 <0.3.0`,
        // so `0.3.0` is out of range and the highest in-range wins.
        $r = new RefResolver();

        Assert::same($r->resolveCaret('^0.2.3', ['0.2.3', '0.2.5', '0.3.0']), '0.2.5');
    }

    public function caretPre1BelowFloorIsRejected(): void
    {
        $r = new RefResolver();

        Assert::same($r->resolveCaret('^0.2.3', ['0.2.2', '0.3.0']), null);
    }

    public function caretPre1WithZeroMinorLocksThePatch(): void
    {
        // `^0.0.3` means `>=0.0.3 <0.0.4` — only the exact patch matches.
        $r = new RefResolver();

        Assert::same($r->resolveCaret('^0.0.3', ['0.0.3', '0.0.4', '0.1.0']), '0.0.3');
    }

    public function formatCaretAndResolveCaretRoundTripForPre1Tag(): void
    {
        // Regression: `skills:add` on a 0.x donor stores whatever
        // `formatCaret()` returns, then the sync feeds it straight back
        // into `resolveCaret()`. The two must agree, or the just-added
        // skill never loads.
        $r = new RefResolver();
        $constraint = $r->formatCaret('0.10.38');

        Assert::same($constraint, '^0.10.38');
        Assert::same(
            $r->resolveCaret((string) $constraint, ['0.10.37', '0.10.38', '0.11.0']),
            '0.10.38',
        );
    }

    public function caretMalformedReturnsNull(): void
    {
        $r = new RefResolver();

        Assert::same($r->resolveCaret('not-a-constraint', ['1.0.0']), null);
        Assert::same($r->resolveCaret('1.0.0', ['1.0.0']), null);
    }

    public function formatCaretFromStableTag(): void
    {
        $r = new RefResolver();

        Assert::same($r->formatCaret('1.2.3'), '^1.2.3');
        Assert::same($r->formatCaret('v1.2.3'), '^1.2.3');
    }

    public function formatCaretReturnsNullForPrereleaseOrNonSemver(): void
    {
        // Caret formation only happens for stable tags. A prerelease
        // or non-semver tag tells `skills:add` to omit the `ref`
        // field entirely.
        $r = new RefResolver();

        Assert::same($r->formatCaret('v1.2.3-rc.1'), null);
        Assert::same($r->formatCaret('main'), null);
    }

    public function isCaretConstraintMatchesStorageShapes(): void
    {
        $r = new RefResolver();

        Assert::true($r->isCaretConstraint('^1.2.3'));
        Assert::true($r->isCaretConstraint('^1'));
        Assert::true($r->isCaretConstraint('^v1.2.3'));

        Assert::false($r->isCaretConstraint('1.2.3'));
        Assert::false($r->isCaretConstraint('main'));
        Assert::false($r->isCaretConstraint('>=1.2.3'));
    }
}
