<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config;

use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\VendorPattern;
use Testo\Assert;
use Testo\Test;

#[Test]
final class SyncOptionsTest
{
    public function defaultHasNoFilters(): void
    {
        $o = SyncOptions::default();

        Assert::false($o->hasPackageFilters());
        Assert::same($o->packageFilters, []);
        Assert::same($o->extraTrusted, []);
        Assert::same($o->targetOverride, null);
        Assert::same($o->interactive, false);
    }

    public function emptyFilterMatchesEveryPackage(): void
    {
        // No positional args ⇒ sync considers every donor package.
        $o = SyncOptions::default();

        Assert::true($o->matchesFilter('any/pkg'));
        Assert::true($o->matchesFilter('other/thing'));
    }

    public function hasPackageFiltersReflectsPositionalArgs(): void
    {
        $o = new SyncOptions(
            packageFilters: [VendorPattern::fromString('acme/*')],
            extraTrusted: [],
            targetOverride: null,
            interactive: false,
        );

        Assert::true($o->hasPackageFilters());
    }

    public function matchesFilterIsOrOverPatterns(): void
    {
        $o = new SyncOptions(
            packageFilters: [
                VendorPattern::fromString('acme/*'),
                VendorPattern::fromString('foo/bar'),
            ],
            extraTrusted: [],
            targetOverride: null,
            interactive: false,
        );

        Assert::true($o->matchesFilter('acme/anything'));
        Assert::true($o->matchesFilter('foo/bar'));
        Assert::false($o->matchesFilter('foo/baz'));
        Assert::false($o->matchesFilter('other/x'));
    }
}
