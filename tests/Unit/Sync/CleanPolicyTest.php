<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Sync;

use LLM\Skills\Sync\CleanPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CleanPolicy::class)]
final class CleanPolicyTest
{
    public function noCleanMergesWhateverTheConfigSays(): void
    {
        Assert::null(CleanPolicy::resolve(false, true));
    }

    public function neitherFlagNorConfigMerges(): void
    {
        Assert::null(CleanPolicy::resolve(null, false));
    }

    public function aRequestedCleanIsStrict(): void
    {
        $policy = CleanPolicy::resolve(true, false);

        Assert::true($policy?->refusesPartialRestore());
        Assert::true($policy?->refusesUnreadableTarget());
        Assert::true($policy?->asksFirst());
        Assert::true($policy?->failsOnRemovalError());
    }

    public function aConfiguredCleanRunsUnattended(): void
    {
        $policy = CleanPolicy::resolve(null, true);

        Assert::false($policy?->refusesPartialRestore() ?? true);
        Assert::false($policy?->refusesUnreadableTarget() ?? true);
        Assert::false($policy?->asksFirst() ?? true);
        Assert::false($policy?->failsOnRemovalError() ?? true);
    }
}
