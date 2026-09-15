<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config;

use LLM\Skills\Config\InitPresets;
use Testo\Assert;
use Testo\Test;

/**
 * Unit coverage for the config values `skills:init` accepts on the
 * command line and layers onto whatever defaults it resolved.
 */
#[Test]
final class InitPresetsTest
{
    public function nothingGivenChangesNothing(): void
    {
        $defaults = ['target' => 'custom/skills', 'aliases' => ['.claude/skills']];

        Assert::same(InitPresets::none()->apply($defaults), $defaults);
        Assert::true(InitPresets::none()->isEmpty());
    }

    public function givenValuesWinOverTheResolvedDefaults(): void
    {
        $presets = new InitPresets(
            target: '.claude/skills',
            aliases: ['.agents/skills'],
            autoSync: false,
            discovery: false,
        );

        $result = $presets->apply([
            'target' => 'detected/skills',
            'aliases' => ['.cursor/skills'],
        ]);

        Assert::same($result['target'] ?? null, '.claude/skills');
        Assert::same($result['aliases'] ?? null, ['.agents/skills']);
        Assert::same($result['auto-sync'] ?? null, false);
        Assert::same($result['discovery'] ?? null, false);
    }

    public function trustLandsUnderComposerWithoutDisturbingItsSiblings(): void
    {
        // The trust list is one field of one manager's entry; an explicit
        // `enabled: false` and an `npm` block are nobody's business here.
        $result = (new InitPresets(trusted: ['acme/*']))->apply([
            'dependencies' => [
                'composer' => ['enabled' => false],
                'npm' => ['trusted' => ['@scope/*']],
            ],
        ]);

        Assert::same($result['dependencies'] ?? null, [
            'composer' => ['enabled' => false, 'trusted' => ['acme/*']],
            'npm' => ['trusted' => ['@scope/*']],
        ]);
    }

    public function trustReplacesAnInheritedListRatherThanExtendingIt(): void
    {
        $result = (new InitPresets(trusted: ['myorg/*']))->apply([
            'dependencies' => ['composer' => ['trusted' => ['acme/*']]],
        ]);

        Assert::same($result['dependencies'] ?? null, ['composer' => ['trusted' => ['myorg/*']]]);
    }

    public function emptyListsAreNotWrittenOut(): void
    {
        // Both are the built-in default; emitting them would be noise in a
        // freshly generated file.
        $result = (new InitPresets(aliases: [], trusted: []))->apply([
            'aliases' => ['.claude/skills'],
            'dependencies' => ['composer' => ['trusted' => ['acme/*']]],
        ]);

        Assert::false(\array_key_exists('aliases', $result));
        Assert::false(\array_key_exists('dependencies', $result));
    }

    public function anEmptiedTrustListKeepsTheRestOfTheComposerEntry(): void
    {
        $result = (new InitPresets(trusted: []))->apply([
            'dependencies' => ['composer' => ['enabled' => false, 'trusted' => ['acme/*']]],
        ]);

        Assert::same($result['dependencies'] ?? null, ['composer' => ['enabled' => false]]);
    }
}
