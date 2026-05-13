<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config;

use LLM\Skills\Config\TrustedVendors;
use LLM\Skills\Config\VendorPattern;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class TrustedVendorsTest
{
    public function emptyTrustsNothing(): void
    {
        $t = TrustedVendors::empty();

        Assert::same($t->patterns, []);
        Assert::false($t->trusts('any/pkg'));
    }

    public function fromStringsBuildsPatternsAndChecksMembership(): void
    {
        $t = TrustedVendors::fromStrings('acme/*', 'foo/bar');

        Assert::same(\count($t->patterns), 2);
        Assert::true($t->trusts('acme/anything'));
        Assert::true($t->trusts('foo/bar'));
        Assert::false($t->trusts('foo/baz'));
        Assert::false($t->trusts('other/pkg'));
    }

    public function fromStringsRejectsMalformedPattern(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        TrustedVendors::fromStrings('acme'); // bare vendor without slash
    }

    public function mergeProducesUnion(): void
    {
        $a = TrustedVendors::fromStrings('acme/*');
        $b = TrustedVendors::fromStrings('foo/bar');

        $merged = $a->merge($b);

        Assert::same(\count($merged->patterns), 2);
        Assert::true($merged->trusts('acme/x'));
        Assert::true($merged->trusts('foo/bar'));
    }

    public function mergeKeepsEveryPatternFromBothSides(): void
    {
        // Multi-pattern inputs catch mutations that replace a spread with a
        // single-element pick (e.g. `[...$x][0]` instead of `...$x`).
        $a = TrustedVendors::fromStrings('a1/*', 'a2/*');
        $b = TrustedVendors::fromStrings('b1/*', 'b2/*');

        $merged = $a->merge($b);

        Assert::same(\count($merged->patterns), 4);
        Assert::true($merged->trusts('a1/x'));
        Assert::true($merged->trusts('a2/x'));
        Assert::true($merged->trusts('b1/x'));
        Assert::true($merged->trusts('b2/x'));
    }

    public function mergeDoesNotMutateOriginals(): void
    {
        $a = TrustedVendors::fromStrings('acme/*');
        $b = TrustedVendors::fromStrings('foo/bar');

        $a->merge($b);

        Assert::same(\count($a->patterns), 1);
        Assert::same(\count($b->patterns), 1);
        Assert::false($a->trusts('foo/bar'));
        Assert::false($b->trusts('acme/x'));
    }

    public function withAppendsPatternsImmutably(): void
    {
        $a = TrustedVendors::fromStrings('acme/*');
        $b = $a->with(VendorPattern::fromString('foo/bar'));

        Assert::same(\count($a->patterns), 1);
        Assert::same(\count($b->patterns), 2);
        Assert::true($b->trusts('foo/bar'));
        Assert::false($a->trusts('foo/bar'));
    }

    public function withKeepsAllExistingAndAllAppendedPatterns(): void
    {
        // Multi-element existing + multi-element appended — distinguishes a
        // proper spread from a "pick first element" mutation on either side.
        $a = TrustedVendors::fromStrings('a1/*', 'a2/*');

        $b = $a->with(
            VendorPattern::fromString('b1/*'),
            VendorPattern::fromString('b2/*'),
        );

        Assert::same(\count($b->patterns), 4);
        Assert::true($b->trusts('a1/x'));
        Assert::true($b->trusts('a2/x'));
        Assert::true($b->trusts('b1/x'));
        Assert::true($b->trusts('b2/x'));
    }
}
