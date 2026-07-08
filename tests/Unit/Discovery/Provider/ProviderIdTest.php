<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider;

use LLM\Skills\Discovery\Provider\ProviderId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ProviderId::class)]
final class ProviderIdTest
{
    public function composerIsKnownManager(): void
    {
        Assert::true(ProviderId::isKnownManager(ProviderId::COMPOSER));
        Assert::true(ProviderId::isKnownManager(ProviderId::NPM));
        Assert::true(ProviderId::isKnownManager(ProviderId::GO));
    }

    public function githubIsNotKnownManager(): void
    {
        // github / gitlab / bitbucket are remote-only adapters — they
        // cannot appear as keys under `dependencies`.
        Assert::false(ProviderId::isKnownManager(ProviderId::GITHUB));
        Assert::false(ProviderId::isKnownManager('whatever'));
    }

    public function knownSourceCoversAllAdapters(): void
    {
        Assert::true(ProviderId::isKnownSource(ProviderId::GITHUB));
        Assert::true(ProviderId::isKnownSource(ProviderId::GITLAB));
        Assert::true(ProviderId::isKnownSource(ProviderId::BITBUCKET));
        Assert::true(ProviderId::isKnownSource(ProviderId::COMPOSER));
        Assert::true(ProviderId::isKnownSource(ProviderId::NPM));
        Assert::true(ProviderId::isKnownSource(ProviderId::GO));
        Assert::true(ProviderId::isKnownSource(ProviderId::SKILLS_SH));
        Assert::true(ProviderId::isKnownSource(ProviderId::HTTP));
        Assert::true(ProviderId::isKnownSource(ProviderId::ZIP));
        Assert::true(ProviderId::isKnownSource(ProviderId::DIR));

        Assert::false(ProviderId::isKnownSource('unknown'));
    }

    public function httpAndZipAreUrlOnly(): void
    {
        Assert::true(ProviderId::isUrlOnlySource(ProviderId::HTTP));
        Assert::true(ProviderId::isUrlOnlySource(ProviderId::ZIP));
    }

    public function nameBasedAdaptersAreNotUrlOnly(): void
    {
        Assert::false(ProviderId::isUrlOnlySource(ProviderId::GITHUB));
        Assert::false(ProviderId::isUrlOnlySource(ProviderId::COMPOSER));
    }

    public function dirIsPathOnly(): void
    {
        // `dir` identifies its donor by `path`, not a package name or
        // URL — the third identifier category alongside url-only.
        Assert::true(ProviderId::isPathOnlySource(ProviderId::DIR));
    }

    public function otherAdaptersAreNotPathOnly(): void
    {
        Assert::false(ProviderId::isPathOnlySource(ProviderId::GITHUB));
        Assert::false(ProviderId::isPathOnlySource(ProviderId::COMPOSER));
        Assert::false(ProviderId::isPathOnlySource(ProviderId::HTTP));
        Assert::false(ProviderId::isPathOnlySource(ProviderId::ZIP));
    }

    public function dirIsNotAUrlOnlyAdapter(): void
    {
        // Path-only and url-only are disjoint categories.
        Assert::false(ProviderId::isUrlOnlySource(ProviderId::DIR));
    }

    public function dirIsNotKnownManager(): void
    {
        // `dir` is an explicit declaration under sources[], not an
        // ecosystem auto-discovery toggle under `dependencies`.
        Assert::false(ProviderId::isKnownManager(ProviderId::DIR));
    }

    public function composerDefaultsToEnabled(): void
    {
        // Preserves the pre-`dependencies` behaviour: projects without
        // an explicit `dependencies.composer` keep getting the Composer
        // provider.
        Assert::true(ProviderId::defaultManagerEnabled(ProviderId::COMPOSER));
    }

    public function nonComposerManagersDefaultToDisabled(): void
    {
        // Opt-in until the provider implementation lands.
        Assert::false(ProviderId::defaultManagerEnabled(ProviderId::NPM));
        Assert::false(ProviderId::defaultManagerEnabled(ProviderId::GO));
    }
}
