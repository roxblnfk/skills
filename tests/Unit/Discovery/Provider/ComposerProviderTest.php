<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider;

use Internal\Path;
use LLM\Skills\Discovery\Provider\ComposerProvider;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Unit coverage for {@see ComposerProvider} — focused on the
 * `enabled` toggle introduced for spec §3.2 `local.composer`. The
 * Composer-dependent code path is exercised end-to-end by the
 * acceptance suite (which actually has a Composer instance); here we
 * only pin down the toggle behaviour.
 */
#[Test]
#[Covers(ComposerProvider::class)]
final class ComposerProviderTest
{
    public function inactiveWhenComposerNull(): void
    {
        $provider = new ComposerProvider(null);

        Assert::false($provider->isActive(self::root()));
        Assert::same($provider->discover(self::root())->donors, []);
        Assert::same($provider->directDependencies(self::root()), []);
    }

    public function inactiveWhenDisabledEvenWithoutComposer(): void
    {
        // `enabled = false` is the strict opt-out — it suppresses the
        // provider regardless of whether a Composer instance is around.
        $provider = new ComposerProvider(null, enabled: false);

        Assert::false($provider->isActive(self::root()));
    }

    public function defaultsToEnabledTrue(): void
    {
        // No second argument == old behaviour: the provider is "on"
        // whenever a Composer instance is supplied. Without Composer the
        // toggle is moot (already inactive), but the constructor default
        // must stay `true` so existing callers see no behavioural drift.
        $reflected = new \ReflectionClass(ComposerProvider::class);
        $ctor = $reflected->getConstructor();
        Assert::notSame($ctor, null);

        $enabledParam = null;
        foreach ($ctor->getParameters() as $param) {
            if ($param->getName() === 'enabled') {
                $enabledParam = $param;
                break;
            }
        }
        Assert::notSame($enabledParam, null, 'enabled parameter must exist');
        Assert::true($enabledParam->isDefaultValueAvailable());
        Assert::same($enabledParam->getDefaultValue(), true);
    }

    private static function root(): Path
    {
        return Path::create(\sys_get_temp_dir());
    }
}
