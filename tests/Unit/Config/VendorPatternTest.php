<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config;

use LLM\Skills\Config\VendorPattern;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
final class VendorPatternTest
{
    public function fromStringExactPackage(): void
    {
        $p = VendorPattern::fromString('acme/foo');

        Assert::same($p->raw, 'acme/foo');
        Assert::same($p->vendor, 'acme');
        Assert::same($p->package, 'foo');
    }

    public function fromStringWildcardPackage(): void
    {
        $p = VendorPattern::fromString('acme/*');

        Assert::same($p->raw, 'acme/*');
        Assert::same($p->vendor, 'acme');
        Assert::same($p->package, null);
    }

    public function fromStringRejectsBareVendorNoSlash(): void
    {
        // strpos returns false ⇒ first clause of the guard fires.
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('acme');

        VendorPattern::fromString('acme');
    }

    public function fromStringRejectsMultipleSlashes(): void
    {
        // Composer package names contain exactly one slash. Multi-slash
        // patterns are nonsense (`a/b/c` cannot match any installed package)
        // and used to silently parse with `b/c` as the package segment,
        // which also disagreed with `resources/skills.schema.json`.
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('exactly one');

        VendorPattern::fromString('acme/foo/bar');
    }

    public function fromStringRejectsLeadingSlash(): void
    {
        // strpos returns 0 ⇒ second clause fires (empty vendor before slash).
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('/acme');

        VendorPattern::fromString('/acme');
    }

    public function fromStringRejectsTrailingSlash(): void
    {
        // strpos returns strlen-1 ⇒ third clause fires (empty package after slash).
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('acme/');

        VendorPattern::fromString('acme/');
    }

    public function fromStringRejectsJustASlash(): void
    {
        // strpos returns 0 AND strpos === strlen-1 ⇒ both middle and last clauses fire.
        Expect::exception(\InvalidArgumentException::class);

        VendorPattern::fromString('/');
    }

    #[DataSet(['acme/*', 'acme/foo', true], name: 'wildcard matches a package in vendor')]
    #[DataSet(['acme/*', 'acme/bar', true], name: 'wildcard matches another package in vendor')]
    #[DataSet(['acme/*', 'other/foo', false], name: 'wildcard rejects different vendor')]
    #[DataSet(['acme/foo', 'acme/foo', true], name: 'exact match')]
    #[DataSet(['acme/foo', 'acme/bar', false], name: 'exact rejects different package')]
    #[DataSet(['acme/foo', 'other/foo', false], name: 'exact rejects different vendor')]
    #[DataSet(['acme/foo', 'bare-name', false], name: 'rejects package name without slash')]
    public function matches(string $pattern, string $packageName, bool $expected): void
    {
        Assert::same(
            VendorPattern::fromString($pattern)->matches($packageName),
            $expected,
        );
    }
}
