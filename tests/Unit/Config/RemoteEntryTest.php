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

    public function constructorRejectsBothPackageAndUrlNull(): void
    {
        // The exactly-one-of-(package, url) invariant is the contract
        // downstream methods rely on (identifier(), compositeKey()).
        // Enforcing it in the constructor keeps direct callers honest
        // — the mapper's pre-flight check is one of several lines of
        // defence, not the only one.
        try {
            new RemoteEntry('github', null, null, null, null);
            Assert::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::true(\str_contains($e->getMessage(), 'neither set'));
        }
    }

    public function constructorRejectsBothPackageAndUrlSet(): void
    {
        try {
            new RemoteEntry('github', 'acme/skills', 'https://example.com/x.zip', null, null);
            Assert::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::true(\str_contains($e->getMessage(), 'both set'));
        }
    }

    public function skillsDefaultsToNull(): void
    {
        // No allowlist set ⇒ sync every skill the donor ships.
        $entry = new RemoteEntry('github', 'acme/skills', null, null, null);

        Assert::same($entry->skills, null);
    }

    public function skillsPreservesListWhenSet(): void
    {
        $entry = new RemoteEntry(
            from: 'github',
            package: 'acme/skills',
            url: null,
            host: null,
            ref: null,
            skills: ['code-review', 'refactor'],
        );

        Assert::same($entry->skills, ['code-review', 'refactor']);
    }

    public function constructorAcceptsEmptySkillsList(): void
    {
        // Empty list = "donor registered, no skills pulled from it".
        // Distinct from `null` (= "sync every skill").
        $entry = new RemoteEntry(
            from: 'github',
            package: 'acme/skills',
            url: null,
            host: null,
            ref: null,
            skills: [],
        );

        Assert::same($entry->skills, []);
    }

    public function constructorRejectsSkillsWithEmptyName(): void
    {
        try {
            new RemoteEntry(
                from: 'github',
                package: 'acme/skills',
                url: null,
                host: null,
                ref: null,
                skills: ['valid-name', ''],
            );
            Assert::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::true(\str_contains($e->getMessage(), 'non-empty strings'));
        }
    }
}
