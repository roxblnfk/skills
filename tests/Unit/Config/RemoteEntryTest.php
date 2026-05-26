<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config;

use LLM\Skills\Config\RemoteEntry;
use LLM\Skills\Discovery\Provider\ProviderId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RemoteEntry::class)]
final class RemoteEntryTest
{
    public function identifierReturnsPackageWhenSet(): void
    {
        $entry = new RemoteEntry(
            from: ProviderId::GITHUB,
            package: 'acme/skills',
            url: null,
            host: null,
            ref: null,
        );

        Assert::same($entry->identifier(), 'acme/skills');
    }

    public function identifierReturnsUrlWhenPackageNull(): void
    {
        $entry = new RemoteEntry(
            from: ProviderId::ZIP,
            package: null,
            url: 'https://example.com/x.zip',
            host: null,
            ref: null,
        );

        Assert::same($entry->identifier(), 'https://example.com/x.zip');
    }

    public function compositeKeyCombinesFromHostAndIdentifier(): void
    {
        $entry = new RemoteEntry(
            from: ProviderId::GITHUB,
            package: 'acme/skills',
            url: null,
            host: 'https://github.corp.example.com',
            ref: null,
        );

        Assert::same(
            $entry->compositeKey(),
            'github|https://github.corp.example.com|acme/skills',
        );
    }

    public function compositeKeyOmitsHostWhenAbsent(): void
    {
        // Distinguishes "no host" from any explicit host string. Two
        // entries differ only if both omit host or both spell the
        // same host — the adapter's default-host fill-in is a runtime
        // concern, not a config-load concern.
        $a = new RemoteEntry(ProviderId::GITHUB, 'acme/skills', null, null, null);
        $b = new RemoteEntry(ProviderId::GITHUB, 'acme/skills', null, '', null);

        Assert::same($a->compositeKey(), 'github||acme/skills');
        // (b cannot be constructed via mapper — non-empty-string — but
        // the VO itself does not enforce non-empty; mapper does.)
        Assert::same($b->compositeKey(), 'github||acme/skills');
    }

    public function extrasDefaultToEmptyMap(): void
    {
        $entry = new RemoteEntry(ProviderId::GITHUB, 'acme/skills', null, null, null);

        Assert::same($entry->extras, []);
    }

    public function extrasPreservedVerbatim(): void
    {
        // Adapter-specific keys are stored as-is; the mapper does not
        // validate them. `zip` adapter's `sha256` is the canonical
        // example.
        $entry = new RemoteEntry(
            from: ProviderId::ZIP,
            package: null,
            url: 'https://example.com/x.zip',
            host: null,
            ref: null,
            extras: ['sha256' => 'abcd1234'],
        );

        Assert::same($entry->extras, ['sha256' => 'abcd1234']);
    }
}
