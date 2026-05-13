<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config;

use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Config\TrustedVendors;
use Testo\Assert;
use Testo\Test;

#[Test]
final class ProjectConfigTest
{
    public function defaultUsesDefaultTargetAndEmptyTrust(): void
    {
        $c = ProjectConfig::default();

        Assert::same($c->target, ProjectConfig::DEFAULT_TARGET);
        Assert::same($c->trustedReplace, false);
        Assert::same($c->trusted->patterns, []);
    }

    public function withTargetReturnsNewInstanceAndKeepsOtherFields(): void
    {
        $a = new ProjectConfig(
            target: '.claude/skills',
            trusted: TrustedVendors::fromStrings('acme/*'),
            trustedReplace: true,
        );

        $b = $a->withTarget('custom/dir');

        Assert::same($b->target, 'custom/dir');
        Assert::same($b->trustedReplace, true);
        Assert::same($b->trusted, $a->trusted);
    }

    public function withTargetDoesNotMutateOriginal(): void
    {
        $a = ProjectConfig::default();
        $a->withTarget('custom/dir');

        Assert::same($a->target, ProjectConfig::DEFAULT_TARGET);
    }
}
