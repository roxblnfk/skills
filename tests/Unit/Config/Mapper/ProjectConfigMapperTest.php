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

    public function aliasesDefaultToEmptyListWhenAbsent(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => ['target' => 'custom/skills']]);

        Assert::same($cfg->aliases, []);
    }

    public function aliasesDefaultToEmptyListWhenExplicitEmpty(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => ['aliases' => []]]);

        Assert::same($cfg->aliases, []);
    }

    public function mapsAliasesAsConfigured(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'target' => '.agents/skills',
                'aliases' => ['.claude/skills', '.cursor/skills'],
            ],
        ]);

        Assert::same($cfg->aliases, ['.claude/skills', '.cursor/skills']);
    }

    public function aliasesNotAListThrows(): void
    {
        // Associative arrays are rejected — `aliases` is a list-shape only,
        // any keys other than 0..N break the contract.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.aliases');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => ['aliases' => ['one' => '.claude/skills']],
        ]);
    }

    public function aliasesScalarThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.aliases');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['aliases' => '.claude/skills']]);
    }

    public function aliasesNonStringEntryThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.aliases');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['aliases' => [42]]]);
    }

    public function aliasesEmptyStringEntryThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.aliases');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['aliases' => ['']]]);
    }

    public function aliasEqualToTargetThrows(): void
    {
        // An alias of itself is nonsense — caught lexically up front so
        // misconfigurations never reach the planner.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('cannot equal extra.skills.target');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'target' => '.agents/skills',
                'aliases' => ['.agents/skills'],
            ],
        ]);
    }

    public function aliasEqualToTargetIsDetectedAfterLexicalNormalisation(): void
    {
        // Backslash vs forward slash and trailing-slash variants must not
        // sneak past the equality check.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('cannot equal extra.skills.target');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'target' => '.agents/skills',
                'aliases' => ['.agents\\skills/'],
            ],
        ]);
    }

    public function duplicateAliasesThrow(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('duplicates an earlier entry');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'aliases' => ['.claude/skills', '.claude/skills'],
            ],
        ]);
    }
}
