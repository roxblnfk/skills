<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config\Mapper;

use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
final class ProjectConfigMapperTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUpTmp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-mapper-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDownTmp(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

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

    public function autoSyncDefaultsToFalse(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => []]);

        Assert::same($cfg->autoSync, false);
    }

    public function autoSyncIsMappedWhenTrue(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => ['auto-sync' => true]]);

        Assert::same($cfg->autoSync, true);
    }

    public function autoSyncIsMappedWhenExplicitlyFalse(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => ['auto-sync' => false]]);

        Assert::same($cfg->autoSync, false);
    }

    public function autoSyncNonBoolThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.auto-sync');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['auto-sync' => 'yes']]);
    }

    // ── forProject(): decision tree between skills.json and inline ──────

    public function forProjectFallsBackToInlineWhenSkillsJsonAbsent(): void
    {
        // No skills.json in $tmp; the mapper must use the inline block
        // exactly as fromExtra() would, and report no shadowed keys.
        $resolution = (new ProjectConfigMapper())->forProject(
            Path::create($this->tmp),
            ['skills' => ['target' => 'inline/skills']],
        );

        Assert::same($resolution->config->target, 'inline/skills');
        Assert::same($resolution->ignoredInlineKeys, []);
    }

    public function forProjectLoadsSkillsJsonWhenPresent(): void
    {
        $this->writeSkillsJson(['target' => 'external/skills']);

        $resolution = (new ProjectConfigMapper())->forProject(
            Path::create($this->tmp),
            ['skills' => ['target' => 'inline/skills']],
        );

        Assert::same(
            $resolution->config->target,
            'external/skills',
            'skills.json must win when both sources are present',
        );
    }

    public function forProjectListsShadowedInlineProjectKeys(): void
    {
        // skills.json is present, so inline project keys are shadowed.
        // The caller surfaces this list under -v.
        $this->writeSkillsJson(['target' => 'external/skills']);

        $resolution = (new ProjectConfigMapper())->forProject(
            Path::create($this->tmp),
            ['skills' => [
                'target' => 'inline/skills',
                'aliases' => ['.claude/skills'],
                'auto-sync' => true,
            ]],
        );

        Assert::same($resolution->ignoredInlineKeys, ['target', 'aliases', 'auto-sync']);
    }

    public function forProjectDoesNotListSourceAsShadowed(): void
    {
        // `source` is a donor-side key — the same root package may
        // legitimately ship its own skills via `source` while also
        // being a consumer via skills.json. The warning must not
        // accuse `source` of being shadowed.
        $this->writeSkillsJson(['target' => 'external/skills']);

        $resolution = (new ProjectConfigMapper())->forProject(
            Path::create($this->tmp),
            ['skills' => [
                'source' => 'resources/skills',
                'target' => 'inline/skills',
            ]],
        );

        Assert::same(
            $resolution->ignoredInlineKeys,
            ['target'],
            'source is a donor key and must not appear in the shadowed-keys list',
        );
    }

    public function forProjectEmitsEmptyShadowedListWhenInlineHasOnlyDonorKey(): void
    {
        // skills.json + inline `source` only → nothing is shadowed,
        // because no project-level inline key competes with it.
        $this->writeSkillsJson(['target' => 'external/skills']);

        $resolution = (new ProjectConfigMapper())->forProject(
            Path::create($this->tmp),
            ['skills' => ['source' => 'resources/skills']],
        );

        Assert::same($resolution->ignoredInlineKeys, []);
    }

    public function forProjectPropagatesExternalErrors(): void
    {
        \file_put_contents($this->tmp . '/skills.json', '{ bad json');

        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('skills.json:');

        (new ProjectConfigMapper())->forProject(Path::create($this->tmp), null);
    }

    public function forProjectWithMissingSkillsJsonAndNullExtraReturnsDefaults(): void
    {
        $resolution = (new ProjectConfigMapper())->forProject(Path::create($this->tmp), null);

        Assert::same($resolution->config->target, ProjectConfig::DEFAULT_TARGET);
        Assert::same($resolution->ignoredInlineKeys, []);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeSkillsJson(array $data): void
    {
        \file_put_contents(
            $this->tmp . '/skills.json',
            \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
        );
    }
}
