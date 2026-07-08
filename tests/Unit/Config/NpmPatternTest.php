<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config;

use LLM\Skills\Config\NpmPattern;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(NpmPattern::class)]
final class NpmPatternTest
{
    public function fromStringBareName(): void
    {
        $p = NpmPattern::fromString('lodash');

        Assert::same($p->raw(), 'lodash');
        Assert::same($p->scope, null);
        Assert::same($p->package, 'lodash');
    }

    public function fromStringScopedExact(): void
    {
        $p = NpmPattern::fromString('@anthropic-ai/claude-code');

        Assert::same($p->scope, '@anthropic-ai');
        Assert::same($p->package, 'claude-code');
    }

    public function fromStringScopeWildcard(): void
    {
        $p = NpmPattern::fromString('@anthropic-ai/*');

        Assert::same($p->scope, '@anthropic-ai');
        Assert::same($p->package, null);
    }

    public function fromStringRejectsBareWildcard(): void
    {
        // A bare `*` would trust the whole registry — never allowed.
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('bare wildcard');

        NpmPattern::fromString('*');
    }

    public function fromStringRejectsUnscopedNameWithSlash(): void
    {
        // An unscoped npm name never contains a slash.
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('cannot contain');

        NpmPattern::fromString('foo/bar');
    }

    public function fromStringRejectsScopeWithoutPackage(): void
    {
        // `@scope/` has an empty package segment.
        Expect::exception(\InvalidArgumentException::class);

        NpmPattern::fromString('@scope/');
    }

    public function fromStringRejectsScopedMultiSlash(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('exactly one');

        NpmPattern::fromString('@scope/a/b');
    }

    public function fromStringRejectsUnscopedNameWithWildcard(): void
    {
        // `lodash*` is not a real npm name — only a scope may carry the
        // wildcard, so this can never match anything.
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('cannot contain "*"');

        NpmPattern::fromString('lodash*');
    }

    public function fromStringRejectsEmptyScopeName(): void
    {
        // `@/*` has a bare `@` with no scope name behind it.
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('scope segment after "@" must not be empty');

        NpmPattern::fromString('@/*');
    }

    public function fromStringRejectsWildcardInScopeSegment(): void
    {
        // A `*` in the scope segment (`@sc*ope/x`) never matches a real name.
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('scope segment cannot contain "*"');

        NpmPattern::fromString('@sc*ope/x');
    }

    public function fromStringRejectsWildcardInsidePackageSegment(): void
    {
        // The package segment is either the whole wildcard (`*`) or a
        // literal name; `@scope/*foo` embeds a `*` and is rejected.
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('package segment must be "*"');

        NpmPattern::fromString('@scope/*foo');
    }

    #[DataSet(['lodash', 'lodash', true], name: 'bare exact match')]
    #[DataSet(['lodash', 'lodash-es', false], name: 'bare rejects a different name')]
    #[DataSet(['lodash', '@scope/lodash', false], name: 'bare rejects a scoped name')]
    #[DataSet(['@scope/pkg', '@scope/pkg', true], name: 'scoped exact match')]
    #[DataSet(['@scope/pkg', '@scope/other', false], name: 'scoped rejects a different package')]
    #[DataSet(['@scope/pkg', 'pkg', false], name: 'scoped rejects the bare package')]
    #[DataSet(['@scope/*', '@scope/anything', true], name: 'wildcard matches a package in the scope')]
    #[DataSet(['@scope/*', '@scope/a/b', true], name: 'wildcard matches a nested path in the scope')]
    #[DataSet(['@scope/*', '@other/pkg', false], name: 'wildcard rejects a different scope')]
    #[DataSet(['@scope/*', 'scope', false], name: 'wildcard rejects the bare scope word')]
    public function matches(string $pattern, string $packageName, bool $expected): void
    {
        Assert::same(
            NpmPattern::fromString($pattern)->matches($packageName),
            $expected,
        );
    }

    public function matchingIsCaseSensitive(): void
    {
        // npm publishes lower-cased names, so the comparison is literal.
        Assert::false(NpmPattern::fromString('@scope/pkg')->matches('@Scope/pkg'));
    }
}
