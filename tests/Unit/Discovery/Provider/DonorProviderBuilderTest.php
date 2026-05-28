<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider;

use Internal\Path;
use LLM\Skills\Discovery\Provider\CompositeDonorProvider;
use LLM\Skills\Discovery\Provider\DonorProviderBuilder;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Unit coverage for {@see DonorProviderBuilder} — focuses on how the
 * `local.composer` toggle flows from skills.json / inline extras into
 * the constructed {@see CompositeDonorProvider}.
 *
 * The Composer-instance is always `null` here because we are not
 * testing Composer's machinery; the only observable property is
 * whether {@see CompositeDonorProvider::isActive()} ends up `true` or
 * `false` based on the toggle and the (absent) Composer instance.
 */
#[Test]
#[Covers(DonorProviderBuilder::class)]
final class DonorProviderBuilderTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-builder-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function buildAlwaysReturnsComposite(): void
    {
        $provider = (new DonorProviderBuilder())->build(
            Path::create($this->tmp),
            composer: null,
            extra: null,
        );

        Assert::true($provider instanceof CompositeDonorProvider);
    }

    public function compositeInactiveWhenNoComposerAndNoRemote(): void
    {
        // No Composer instance, no remote[] in config → no provider can
        // contribute. The composite reports inactive and the runner
        // emits its standard "nothing to sync" message.
        $provider = (new DonorProviderBuilder())->build(
            Path::create($this->tmp),
            composer: null,
            extra: null,
        );

        Assert::false($provider->isActive(Path::create($this->tmp)));
    }

    public function malformedSkillsJsonFallsBackToDefaults(): void
    {
        // The runner is the canonical place to surface
        // MalformedProjectConfig — the builder must not duplicate the
        // error. It quietly defaults every provider to its built-in
        // default and lets the runner's own forProject() call raise the
        // real error, where the user-visible message lives.
        \file_put_contents($this->tmp . '/skills.json', '{ this is not json');

        $provider = (new DonorProviderBuilder())->build(
            Path::create($this->tmp),
            composer: null,
            extra: null,
        );

        // composer-default (true) AND no Composer instance ⇒ inactive,
        // not throwing.
        Assert::false($provider->isActive(Path::create($this->tmp)));
    }
}
