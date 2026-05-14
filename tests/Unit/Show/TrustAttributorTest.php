<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Show;

use LLM\Skills\Config\TrustedVendors;
use LLM\Skills\Show\TrustAttributor;
use LLM\Skills\Show\TrustSource;
use Testo\Assert;
use Testo\Test;

#[Test]
final class TrustAttributorTest
{
    public function returnsProjectWhenProjectListMatches(): void
    {
        $attr = new TrustAttributor(
            builtin: TrustedVendors::fromStrings('acme/*'),
            project: TrustedVendors::fromStrings('myorg/*'),
            cli: TrustedVendors::empty(),
        );

        Assert::same($attr->attribute('myorg/skills'), TrustSource::Project);
    }

    public function returnsCliWhenOnlyCliListMatches(): void
    {
        $attr = new TrustAttributor(
            builtin: TrustedVendors::empty(),
            project: TrustedVendors::empty(),
            cli: TrustedVendors::fromStrings('evil/payload'),
        );

        Assert::same($attr->attribute('evil/payload'), TrustSource::Cli);
    }

    public function returnsBuiltinWhenOnlyBuiltinListMatches(): void
    {
        $attr = new TrustAttributor(
            builtin: TrustedVendors::fromStrings('spiral/*'),
            project: TrustedVendors::empty(),
            cli: TrustedVendors::empty(),
        );

        Assert::same($attr->attribute('spiral/skills-demo'), TrustSource::Builtin);
    }

    public function returnsNullWhenNothingMatches(): void
    {
        $attr = new TrustAttributor(
            builtin: TrustedVendors::fromStrings('acme/*'),
            project: TrustedVendors::fromStrings('myorg/*'),
            cli: TrustedVendors::empty(),
        );

        Assert::null($attr->attribute('evil/payload'));
    }

    public function projectWinsOverBuiltinWhenBothMatch(): void
    {
        // If project explicitly trusts a vendor that builtin also covers,
        // we credit project — that's the canonical answer the user wrote
        // down in their composer.json.
        $attr = new TrustAttributor(
            builtin: TrustedVendors::fromStrings('acme/*'),
            project: TrustedVendors::fromStrings('acme/skills-basic'),
            cli: TrustedVendors::empty(),
        );

        Assert::same($attr->attribute('acme/skills-basic'), TrustSource::Project);
    }

    public function projectWinsOverCliWhenBothMatch(): void
    {
        $attr = new TrustAttributor(
            builtin: TrustedVendors::empty(),
            project: TrustedVendors::fromStrings('acme/*'),
            cli: TrustedVendors::fromStrings('acme/skills-basic'),
        );

        Assert::same($attr->attribute('acme/skills-basic'), TrustSource::Project);
    }

    public function cliWinsOverBuiltinWhenBothMatchAndProjectDoesNot(): void
    {
        $attr = new TrustAttributor(
            builtin: TrustedVendors::fromStrings('acme/*'),
            project: TrustedVendors::empty(),
            cli: TrustedVendors::fromStrings('acme/skills-basic'),
        );

        Assert::same($attr->attribute('acme/skills-basic'), TrustSource::Cli);
    }

    public function nullBuiltinIsSkippedAsIfTrustedReplaceWereTrue(): void
    {
        // When ProjectConfig::trustedReplace is true the builtin list is
        // not in effect — the runner passes null. The attributor must
        // never consult it.
        $attr = new TrustAttributor(
            builtin: null,
            project: TrustedVendors::empty(),
            cli: TrustedVendors::empty(),
        );

        Assert::null($attr->attribute('spiral/skills-demo'));
    }
}
