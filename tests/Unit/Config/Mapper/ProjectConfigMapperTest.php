<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config\Mapper;

use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Config\ProjectConfig;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class ProjectConfigMapperTest
{
    public function nullExtraYieldsDefaults(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(null);

        Assert::same($cfg->target, ProjectConfig::DEFAULT_TARGET);
        Assert::same($cfg->trustedReplace, false);
        Assert::same($cfg->trusted->patterns, []);
    }

    public function emptyArrayExtraYieldsDefaults(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra([]);

        Assert::same($cfg->target, ProjectConfig::DEFAULT_TARGET);
    }

    public function missingSkillsBlockYieldsDefaults(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['unrelated' => 'value']);

        Assert::same($cfg->target, ProjectConfig::DEFAULT_TARGET);
        Assert::same($cfg->trusted->patterns, []);
    }

    public function mapsFullValidConfig(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'target' => 'custom/skills',
                'trusted' => ['acme/*', 'foo/bar'],
                'trusted-replace' => true,
            ],
        ]);

        Assert::same($cfg->target, 'custom/skills');
        Assert::same($cfg->trustedReplace, true);
        Assert::same(\count($cfg->trusted->patterns), 2);
        Assert::true($cfg->trusted->trusts('acme/anything'));
        Assert::true($cfg->trusted->trusts('foo/bar'));
        Assert::false($cfg->trusted->trusts('foo/baz'));
    }

    public function defaultsWhenSkillsBlockIsEmpty(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => []]);

        Assert::same($cfg->target, ProjectConfig::DEFAULT_TARGET);
        Assert::same($cfg->trustedReplace, false);
        Assert::same($cfg->trusted->patterns, []);
    }

    public function nonArrayRootExtraThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class);

        (new ProjectConfigMapper())->fromExtra('not-an-array');
    }

    public function skillsBlockWrongTypeThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills');

        (new ProjectConfigMapper())->fromExtra(['skills' => 'string']);
    }

    public function targetEmptyStringThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.target');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['target' => '']]);
    }

    public function targetNonStringThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.target');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['target' => 42]]);
    }

    public function trustedNotAListThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.trusted');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['trusted' => 'acme/*']]);
    }

    public function trustedEmptyStringEntryThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.trusted');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['trusted' => ['']]]);
    }

    public function trustedNonStringEntryThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.trusted');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['trusted' => [42]]]);
    }

    public function trustedBareVendorPatternThrows(): void
    {
        // Bare `vendor` (no slash) is ambiguous and rejected.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.trusted');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['trusted' => ['acme']]]);
    }

    public function trustedReplaceNonBoolThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.trusted-replace');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['trusted-replace' => 'yes']]);
    }

    public function discoveryDefaultsToFalse(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => []]);

        Assert::same($cfg->discovery, false);
    }

    public function discoveryIsMappedWhenTrue(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => ['discovery' => true]]);

        Assert::same($cfg->discovery, true);
    }

    public function discoveryIsMappedWhenExplicitlyFalse(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => ['discovery' => false]]);

        Assert::same($cfg->discovery, false);
    }

    public function discoveryNonBoolThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.discovery');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['discovery' => 'yes']]);
    }
}
