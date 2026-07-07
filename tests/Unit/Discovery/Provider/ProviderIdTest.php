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
    public function composerIsKnownLocal(): void
    {
        Assert::true(ProviderId::isKnownLocal(ProviderId::COMPOSER));
        Assert::true(ProviderId::isKnownLocal(ProviderId::NPM));
        Assert::true(ProviderId::isKnownLocal(ProviderId::GO));
    }

    public function githubIsNotKnownLocal(): void
    {
        // github / gitlab / bitbucket are remote-only adapters — they
        // cannot appear as keys under `local`.
        Assert::false(ProviderId::isKnownLocal(ProviderId::GITHUB));
        Assert::false(ProviderId::isKnownLocal('whatever'));
    }

    public function knownRemoteCoversAllSpecAdapters(): void
    {
        Assert::true(ProviderId::isKnownRemote(ProviderId::GITHUB));
        Assert::true(ProviderId::isKnownRemote(ProviderId::GITLAB));
        Assert::true(ProviderId::isKnownRemote(ProviderId::BITBUCKET));
        Assert::true(ProviderId::isKnownRemote(ProviderId::COMPOSER));
        Assert::true(ProviderId::isKnownRemote(ProviderId::NPM));
        Assert::true(ProviderId::isKnownRemote(ProviderId::GO));
        Assert::true(ProviderId::isKnownRemote(ProviderId::SKILLS_SH));
        Assert::true(ProviderId::isKnownRemote(ProviderId::HTTP));
        Assert::true(ProviderId::isKnownRemote(ProviderId::ZIP));
        Assert::true(ProviderId::isKnownRemote(ProviderId::DIR));

        Assert::false(ProviderId::isKnownRemote('unknown'));
    }

    public function httpAndZipAreUrlOnly(): void
    {
        Assert::true(ProviderId::isUrlOnlyRemote(ProviderId::HTTP));
        Assert::true(ProviderId::isUrlOnlyRemote(ProviderId::ZIP));
    }

    public function nameBasedAdaptersAreNotUrlOnly(): void
    {
        Assert::false(ProviderId::isUrlOnlyRemote(ProviderId::GITHUB));
        Assert::false(ProviderId::isUrlOnlyRemote(ProviderId::COMPOSER));
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
        Assert::false(ProviderId::isUrlOnlyRemote(ProviderId::DIR));
    }

    public function dirIsNotKnownLocal(): void
    {
        // `dir` is an explicit declaration under sources[], not an
        // ecosystem auto-discovery toggle under `local`.
        Assert::false(ProviderId::isKnownLocal(ProviderId::DIR));
    }

    public function composerDefaultsToEnabled(): void
    {
        // Preserves the pre-`local` behaviour: projects without an
        // explicit `local.composer` keep getting the Composer provider.
        Assert::true(ProviderId::defaultLocalEnabled(ProviderId::COMPOSER));
    }

    public function nonComposerLocalsDefaultToDisabled(): void
    {
        // Opt-in until the provider implementation lands.
        Assert::false(ProviderId::defaultLocalEnabled(ProviderId::NPM));
        Assert::false(ProviderId::defaultLocalEnabled(ProviderId::GO));
    }
}
