<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config;

use LLM\Skills\Config\SourceEntry;
use LLM\Skills\Discovery\Provider\ProviderId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SourceEntry::class)]
final class SourceEntryTest
{
    public function identifierReturnsPackageWhenSet(): void
    {
        $entry = new SourceEntry(
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
        $entry = new SourceEntry(
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
        $entry = new SourceEntry(
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
        $a = new SourceEntry(ProviderId::GITHUB, 'acme/skills', null, null, null);
        $b = new SourceEntry(ProviderId::GITHUB, 'acme/skills', null, '', null);

        Assert::same($a->compositeKey(), 'github||acme/skills');
        // (b cannot be constructed via mapper — non-empty-string — but
        // the VO itself does not enforce non-empty; mapper does.)
        Assert::same($b->compositeKey(), 'github||acme/skills');
    }

    public function extrasDefaultToEmptyMap(): void
    {
        $entry = new SourceEntry(ProviderId::GITHUB, 'acme/skills', null, null, null);

        Assert::same($entry->extras, []);
    }

    public function extrasPreservedVerbatim(): void
    {
        // Adapter-specific keys are stored as-is; the mapper does not
        // validate them. `zip` adapter's `sha256` is the canonical
        // example.
        $entry = new SourceEntry(
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
            new SourceEntry('github', null, null, null, null);
            Assert::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::true(\str_contains($e->getMessage(), 'neither set'));
        }
    }

    public function constructorRejectsBothPackageAndUrlSet(): void
    {
        try {
            new SourceEntry('github', 'acme/skills', 'https://example.com/x.zip', null, null);
            Assert::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::true(\str_contains($e->getMessage(), 'both set'));
        }
    }

    public function skillsDefaultsToNull(): void
    {
        // No allowlist set ⇒ sync every skill the donor ships.
        $entry = new SourceEntry('github', 'acme/skills', null, null, null);

        Assert::same($entry->skills, null);
    }

    public function skillsPreservesListWhenSet(): void
    {
        $entry = new SourceEntry(
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
        $entry = new SourceEntry(
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
            new SourceEntry(
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

    public function identifierReturnsPathForDirEntry(): void
    {
        // Path-only adapters identify the donor by `path`, even when a
        // `package` name override is also present.
        $entry = new SourceEntry(
            from: ProviderId::DIR,
            package: null,
            url: null,
            host: null,
            ref: null,
            path: './skills',
        );

        Assert::same($entry->identifier(), './skills');
    }

    public function identifierPrefersPathOverPackageOverride(): void
    {
        $entry = new SourceEntry(
            from: ProviderId::DIR,
            package: 'myorg/shared',
            url: null,
            host: null,
            ref: null,
            path: '../shared-skills',
        );

        Assert::same($entry->identifier(), '../shared-skills');
    }

    public function compositeKeyUsesPathForDirEntry(): void
    {
        // Shape: `dir||<path>`. Two entries with the same path collide;
        // the identifier is the raw path string (lexical identity only).
        $entry = new SourceEntry(
            from: ProviderId::DIR,
            package: null,
            url: null,
            host: null,
            ref: null,
            path: './skills',
        );

        Assert::same($entry->compositeKey(), 'dir||./skills');
    }

    public function constructorRejectsDirWithoutPath(): void
    {
        // `path` is mandatory for path-only adapters.
        try {
            new SourceEntry(
                from: ProviderId::DIR,
                package: null,
                url: null,
                host: null,
                ref: null,
            );
            Assert::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::true(\str_contains($e->getMessage(), 'requires $path'));
        }
    }

    public function constructorRejectsDirWithUrl(): void
    {
        try {
            new SourceEntry(
                from: ProviderId::DIR,
                package: null,
                url: 'https://example.com/x.zip',
                host: null,
                ref: null,
                path: './skills',
            );
            Assert::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::true(\str_contains($e->getMessage(), 'does not allow $url'));
        }
    }

    public function constructorRejectsPathOnNonDirAdapter(): void
    {
        // `path` is meaningless for every non-path-only adapter.
        try {
            new SourceEntry(
                from: ProviderId::GITHUB,
                package: 'acme/skills',
                url: null,
                host: null,
                ref: null,
                path: './skills',
            );
            Assert::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            Assert::true(\str_contains($e->getMessage(), 'does not allow $path'));
        }
    }

    public function dirEntryAcceptsPackageOverride(): void
    {
        // Unlike name-based adapters, `package` on a dir entry is an
        // optional donor-name override and coexists with `path`.
        $entry = new SourceEntry(
            from: ProviderId::DIR,
            package: 'myorg/shared',
            url: null,
            host: null,
            ref: null,
            path: '../shared-skills',
        );

        Assert::same($entry->package, 'myorg/shared');
        Assert::same($entry->path, '../shared-skills');
    }
}
