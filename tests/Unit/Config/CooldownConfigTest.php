<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config;

use LLM\Skills\Config\CooldownConfig;
use LLM\Skills\Config\TrustedVendors;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CooldownConfig::class)]
final class CooldownConfigTest
{
    public function cutoffIsTheGivenNumberOfDaysBeforeNow(): void
    {
        $cooldown = new CooldownConfig(14, TrustedVendors::empty());

        $cutoff = $cooldown->cutoffFor('acme/skills', new \DateTimeImmutable('2026-11-15T12:00:00Z'));

        Assert::same($cutoff?->format(\DATE_ATOM), '2026-11-01T12:00:00+00:00');
    }

    public function zeroDaysTurnsTheCooldownOff(): void
    {
        $cooldown = new CooldownConfig(0, TrustedVendors::empty());

        Assert::null($cooldown->cutoffFor('acme/skills', new \DateTimeImmutable()));
    }

    public function excludedPackageHasNoCutoff(): void
    {
        $cooldown = new CooldownConfig(14, TrustedVendors::fromStrings('acme/*'));

        Assert::null($cooldown->cutoffFor('acme/skills', new \DateTimeImmutable()));
        Assert::notNull($cooldown->cutoffFor('other/skills', new \DateTimeImmutable()));
    }

    public function excludeMatchesThePackageCaseInsensitively(): void
    {
        $cooldown = new CooldownConfig(14, TrustedVendors::fromStrings('myorg/*'));

        Assert::null($cooldown->cutoffFor('MyOrg/Skills', new \DateTimeImmutable()));
    }

    public function entryWithoutPackageCannotBeExcluded(): void
    {
        $cooldown = new CooldownConfig(14, TrustedVendors::fromStrings('acme/*'));

        Assert::notNull($cooldown->cutoffFor(null, new \DateTimeImmutable()));
    }
}
