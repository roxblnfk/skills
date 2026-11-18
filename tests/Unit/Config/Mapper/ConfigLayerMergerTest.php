<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config\Mapper;

use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\Mapper\ConfigLayer;
use LLM\Skills\Config\Mapper\ConfigLayerMerger;
use LLM\Skills\Config\Mapper\ExternalProjectConfigLoader;
use LLM\Skills\Config\Mapper\MergedConfig;
use LLM\Skills\Config\Mapper\MergeStrategy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ConfigLayerMerger::class)]
final class ConfigLayerMergerTest
{
    public function everyKeyAllowedInAConfigFileHasAMergeStrategy(): void
    {
        $keys = \array_values(\array_filter(
            ExternalProjectConfigLoader::ALLOWED_KEYS,
            static fn(string $key): bool => $key !== '$schema',
        ));

        foreach ($keys as $key) {
            Assert::true(
                \array_key_exists($key, ConfigLayerMerger::STRATEGIES),
                \sprintf('key "%s" is allowed in skills.json but has no merge strategy', $key),
            );
        }
        Assert::same(\count(ConfigLayerMerger::STRATEGIES), \count($keys));
    }

    public function deprecatedKeysAreRejectedAboveTheBase(): void
    {
        foreach (['remote', 'trusted', 'trusted-replace', 'local'] as $key) {
            Assert::same(ConfigLayerMerger::STRATEGIES[$key], MergeStrategy::Rejected);
        }
    }

    public function aSingleLayerIsTakenVerbatim(): void
    {
        $merged = (new ConfigLayerMerger())->merge([
            new ConfigLayer('skills.json', ['target' => 'a', 'trusted' => ['x/*']]),
        ]);

        Assert::same($merged->values, ['target' => 'a', 'trusted' => ['x/*']]);
        Assert::same($merged->changes, []);
    }

    public function scalarKeysAreOverridden(): void
    {
        $merged = $this->mergeLocal(
            ['target' => '.agents/skills', 'vendor-sources' => true, 'discovery' => false],
            ['target' => '.claude/skills', 'vendor-sources' => false, 'path-from-root' => 'packages/api'],
        );

        Assert::same($merged->values, [
            'target' => '.claude/skills',
            'vendor-sources' => false,
            'discovery' => false,
            'path-from-root' => 'packages/api',
        ]);
        Assert::same($merged->changes[0]->summary, [
            'target=.claude/skills',
            'vendor-sources=false',
            'path-from-root=packages/api',
        ]);
        Assert::same($merged->changes[0]->changedKeys, ['target', 'vendor-sources', 'path-from-root']);
    }

    public function anOverrideToTheSameValueIsNotAChange(): void
    {
        $merged = $this->mergeLocal(['auto-sync' => false], ['auto-sync' => false]);

        Assert::same($merged->values, ['auto-sync' => false]);
        Assert::same($merged->changes[0]->summary, []);
        Assert::same($merged->changes[0]->describe(), 'skills.local.json: no effective changes');
    }

    public function aKeySetOnlyLocallyIsAChangeEvenAtItsDefault(): void
    {
        $merged = $this->mergeLocal([], ['clean' => false]);

        Assert::same($merged->values, ['clean' => false]);
        Assert::same($merged->changes[0]->summary, ['clean=false']);
    }

    public function aliasesAreUnitedBaseFirstWithoutDuplicates(): void
    {
        $merged = $this->mergeLocal(
            ['aliases' => ['.claude/skills', '.cursor/skills']],
            ['aliases' => ['.cursor/skills', '.codex/skills']],
        );

        Assert::same($merged->values['aliases'], ['.claude/skills', '.cursor/skills', '.codex/skills']);
        Assert::same($merged->changes[0]->summary, ['aliases +1']);
        Assert::same($merged->changes[0]->addedAliases, ['.codex/skills']);
    }

    public function sourcesAreUnitedByIdentity(): void
    {
        $merged = $this->mergeLocal(
            ['sources' => [
                ['from' => 'github', 'package' => 'acme/shared'],
                ['from' => 'dir', 'path' => './team'],
            ]],
            ['sources' => [
                ['from' => 'github', 'package' => 'acme/shared', 'skills' => []],
                ['from' => 'dir', 'path' => './mine'],
            ]],
        );

        Assert::same($merged->values['sources'], [
            ['from' => 'github', 'package' => 'acme/shared', 'skills' => []],
            ['from' => 'dir', 'path' => './team'],
            ['from' => 'dir', 'path' => './mine'],
        ]);
        Assert::same($merged->changes[0]->summary, ['sources +1 ~1']);
    }

    public function aSourceOnAnotherHostIsAppended(): void
    {
        $merged = $this->mergeLocal(
            ['sources' => [['from' => 'github', 'package' => 'acme/x']]],
            ['sources' => [['from' => 'github', 'host' => 'ghe.example.com', 'package' => 'acme/x']]],
        );

        Assert::count((array) $merged->values['sources'], 2);
    }

    public function aDirSourceSpelledDifferentlyIsTheSameSource(): void
    {
        $merged = $this->mergeLocal(
            ['sources' => [['from' => 'dir', 'path' => './local-skills'], ['from' => 'dir', 'path' => './team']]],
            ['sources' => [['from' => 'dir', 'path' => 'local-skills', 'skills' => []]]],
        );

        Assert::same($merged->values['sources'], [
            ['from' => 'dir', 'path' => 'local-skills', 'skills' => []],
            ['from' => 'dir', 'path' => './team'],
        ]);
        Assert::same($merged->changes[0]->summary, ['sources ~1']);
    }

    public function onlyDirPathsCompareAsPaths(): void
    {
        $merged = $this->mergeLocal(
            ['sources' => [['from' => 'github', 'package' => 'acme/x']]],
            ['sources' => [['from' => 'github', 'package' => './acme/x']]],
        );

        Assert::count((array) $merged->values['sources'], 2);
    }

    public function aRepeatedIdenticalSourceIsNotAChange(): void
    {
        $merged = $this->mergeLocal(
            ['sources' => [['from' => 'dir', 'path' => './team']]],
            ['sources' => [['from' => 'dir', 'path' => './team']]],
        );

        Assert::same($merged->changes[0]->summary, []);
    }

    public function aBoolDependencyEntrySetsEnabledOnly(): void
    {
        $merged = $this->mergeLocal(
            ['dependencies' => ['composer' => ['trusted' => ['acme/*'], 'trusted-replace' => true]]],
            ['dependencies' => ['composer' => false]],
        );

        Assert::same($merged->values['dependencies'], [
            'composer' => ['trusted' => ['acme/*'], 'trusted-replace' => true, 'enabled' => false],
        ]);
        Assert::same($merged->changes[0]->summary, ['dependencies.composer.enabled=false']);
    }

    public function dependencyTrustIsUnitedAndTogglesAreOverridden(): void
    {
        $merged = $this->mergeLocal(
            ['dependencies' => ['composer' => true, 'npm' => ['trusted' => ['@acme/*']]]],
            ['dependencies' => [
                'composer' => ['trusted' => ['acme/*', 'spiral/*'], 'trusted-replace' => true],
                'npm' => ['trusted' => ['@acme/*']],
            ]],
        );

        Assert::same($merged->values['dependencies'], [
            'composer' => ['enabled' => true, 'trusted' => ['acme/*', 'spiral/*'], 'trusted-replace' => true],
            'npm' => ['trusted' => ['@acme/*']],
        ]);
        Assert::same($merged->changes[0]->summary, [
            'dependencies.composer.trusted +2',
            'dependencies.composer.trusted-replace=true',
        ]);
        Assert::same($merged->changes[0]->changedKeys, ['dependencies']);
    }

    public function anEmptyLocalDependenciesObjectChangesNothing(): void
    {
        $base = ['dependencies' => ['composer' => ['trusted' => ['acme/*']], 'npm' => true]];

        $merged = $this->mergeLocal($base, ['dependencies' => []]);

        Assert::same($merged->values, $base);
        Assert::same($merged->changes[0]->summary, []);
    }

    public function aliasesCompareAsPaths(): void
    {
        $merged = $this->mergeLocal(
            ['aliases' => ['.claude/skills']],
            ['aliases' => ['.claude/skills/', '.claude\\skills', './.claude/skills', '.claude//skills', '.cursor/skills']],
        );

        Assert::same($merged->values['aliases'], ['.claude/skills', '.cursor/skills']);
        Assert::same($merged->changes[0]->addedAliases, ['.cursor/skills']);
    }

    public function anAbsoluteAliasNeverMatchesARelativeOne(): void
    {
        $merged = $this->mergeLocal(['aliases' => ['skills']], ['aliases' => ['/skills']]);

        Assert::same($merged->values['aliases'], ['skills', '/skills']);
    }

    public function trustPatternsCompareExactly(): void
    {
        $merged = $this->mergeLocal(
            ['dependencies' => ['composer' => ['trusted' => ['acme/*']]]],
            ['dependencies' => ['composer' => ['trusted' => ['acme/*', 'acme/x']]]],
        );

        Assert::same($merged->values['dependencies'], ['composer' => ['trusted' => ['acme/*', 'acme/x']]]);
    }

    public function aDependencyOnlyTheLocalLayerNamesIsAdded(): void
    {
        $merged = $this->mergeLocal([], ['dependencies' => ['npm' => true]]);

        Assert::same($merged->values['dependencies'], ['npm' => ['enabled' => true]]);
        Assert::same($merged->changes[0]->summary, ['dependencies.npm.enabled=true']);
    }

    public function aDeprecatedKeyAboveTheBaseIsFatal(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessage('skills.local.json: deprecated key "remote" is not accepted here; use "sources"');

        $this->mergeLocal([], ['remote' => []]);
    }

    public function aNullTopLevelValueAboveTheBaseIsFatal(): void
    {
        foreach (['target', 'aliases', 'sources', 'dependencies', 'clean'] as $key) {
            try {
                $this->mergeLocal(['target' => 'shared/skills'], [$key => null]);
                Assert::fail(\sprintf('null "%s" must be rejected', $key));
            } catch (MalformedProjectConfig $e) {
                Assert::same($e->getMessage(), \sprintf('skills.local.json: %s must not be null', $key));
            }
        }
    }

    public function aNullDependencyEntryAboveTheBaseIsFatal(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessage('skills.local.json: dependencies.composer must not be null');

        $this->mergeLocal([], ['dependencies' => ['composer' => null]]);
    }

    public function aNullDependencyFieldAboveTheBaseIsFatal(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessage('skills.local.json: dependencies.composer.trusted must not be null');

        $this->mergeLocal(
            ['dependencies' => ['composer' => ['trusted' => ['acme/*']]]],
            ['dependencies' => ['composer' => ['trusted' => null]]],
        );
    }

    public function aNullDeprecatedKeyIsReportedAsDeprecated(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('deprecated key "remote"');

        $this->mergeLocal([], ['remote' => null]);
    }

    public function aLegacyTrustKeyAboveTheBaseIsFatal(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessage('skills.local.json: deprecated key "trusted" is not accepted here; use "dependencies"');

        $this->mergeLocal([], ['trusted' => ['acme/*']]);
    }

    public function theSummaryReadsAsOneLine(): void
    {
        $merged = $this->mergeLocal(
            ['aliases' => ['.claude/skills']],
            [
                'aliases' => ['.cursor/skills'],
                'sources' => [['from' => 'dir', 'path' => './mine']],
                'vendor-sources' => false,
            ],
        );

        Assert::same(
            $merged->changes[0]->describe(),
            'skills.local.json: aliases +1, sources +1, vendor-sources=false',
        );
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $local
     */
    private function mergeLocal(array $base, array $local): MergedConfig
    {
        return (new ConfigLayerMerger())->merge([
            new ConfigLayer('skills.json', $base),
            new ConfigLayer('skills.local.json', $local),
        ]);
    }
}
