<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config;

use LLM\Skills\Config\GoPattern;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(GoPattern::class)]
final class GoPatternTest
{
    public function fromStringExactModulePath(): void
    {
        $p = GoPattern::fromString('github.com/owner/mod');

        Assert::same($p->raw(), 'github.com/owner/mod');
        Assert::same($p->base, 'github.com/owner/mod');
        Assert::false($p->wildcard);
    }

    public function fromStringPrefixWildcard(): void
    {
        $p = GoPattern::fromString('github.com/owner/*');

        Assert::same($p->base, 'github.com/owner');
        Assert::true($p->wildcard);
    }

    public function fromStringRejectsBareWildcard(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('bare wildcard');

        GoPattern::fromString('*');
    }

    public function fromStringRejectsWildcardWithoutPrefix(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('module-path prefix');

        GoPattern::fromString('/*');
    }

    public function fromStringRejectsWildcardInMiddleSegment(): void
    {
        // `github.com/*/mod` puts the wildcard mid-path; it is not the
        // trailing `/*`, so the "exact" path it produces can never match.
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('only allowed as the trailing');

        GoPattern::fromString('github.com/*/mod');
    }

    public function fromStringRejectsWildcardBeforeTrailingSegment(): void
    {
        // `github.com/owner/*/v2` has a `*` that is not the final segment.
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('only allowed as the trailing');

        GoPattern::fromString('github.com/owner/*/v2');
    }

    public function fromStringRejectsWildcardInsideWildcardPrefix(): void
    {
        // The prefix before a trailing `/*` is literal; `github.com/own*er/*`
        // embeds a stray `*` in it.
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('only allowed as the trailing');

        GoPattern::fromString('github.com/own*er/*');
    }

    #[DataSet(['github.com/owner/mod', 'github.com/owner/mod', true], name: 'exact match')]
    #[DataSet(['github.com/owner/mod', 'github.com/owner/other', false], name: 'exact rejects a sibling')]
    #[DataSet(['github.com/owner/mod', 'github.com/owner/mod/v2', false], name: 'exact does not swallow deeper paths')]
    #[DataSet(['github.com/owner/*', 'github.com/owner/repo', true], name: 'wildcard matches one segment')]
    #[DataSet(['github.com/owner/*', 'github.com/owner/repo/v2', true], name: 'wildcard matches a /vN suffix')]
    #[DataSet(['github.com/owner/*', 'github.com/owner/repo/sub/tool', true], name: 'wildcard matches a nested submodule')]
    #[DataSet(['github.com/owner/*', 'github.com/other/repo', false], name: 'wildcard rejects a different owner')]
    #[DataSet(['github.com/owner/*', 'github.com/owner', false], name: 'wildcard needs a trailing segment')]
    #[DataSet(['github.com/owner/*', 'github.com/ownerX/repo', false], name: 'wildcard respects the segment boundary')]
    public function matches(string $pattern, string $packageName, bool $expected): void
    {
        Assert::same(
            GoPattern::fromString($pattern)->matches($packageName),
            $expected,
        );
    }

    public function matchingIsCaseSensitive(): void
    {
        // Go module paths are case-sensitive; the comparison is literal.
        Assert::false(GoPattern::fromString('github.com/owner/*')->matches('github.com/Owner/repo'));
    }
}
