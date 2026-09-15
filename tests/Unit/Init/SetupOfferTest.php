<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Init;

use Composer\Package\RootPackage;
use Internal\Path;
use LLM\Skills\Init\SetupOffer;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Unit coverage for the rule behind the setup offer the plugin makes
 * after `composer install` / `update`.
 */
#[Test]
final class SetupOfferTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-offer-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function aProjectWithNoConfigGetsTheOffer(): void
    {
        Assert::true(SetupOffer::isNeeded(Path::create($this->tmp), $this->rootPackage()));
    }

    public function anExistingSkillsJsonEndsTheOffer(): void
    {
        \file_put_contents($this->tmp . '/skills.json', '{}');

        Assert::false(SetupOffer::isNeeded(Path::create($this->tmp), $this->rootPackage()));
    }

    public function inlineProjectKeysCountAsConfigured(): void
    {
        // A project still on the inline block has made its decisions; it
        // needs the migration `skills:update` already offers, not a setup
        // proposal that would talk past them.
        $package = $this->rootPackage(['skills' => ['target' => 'custom/skills']]);

        Assert::false(SetupOffer::isNeeded(Path::create($this->tmp), $package));
    }

    public function aDonorOnlyBlockIsNotAConsumerConfiguration(): void
    {
        // `source` describes the package as a donor. It says nothing about
        // what this project wants synced into it, so the offer stands.
        $package = $this->rootPackage(['skills' => ['source' => 'skills']]);

        Assert::true(SetupOffer::isNeeded(Path::create($this->tmp), $package));
    }

    public function ourOwnRepositoryIsNeverOfferedASetup(): void
    {
        // The one project where the plugin is installed but is not there
        // to serve the root package.
        $package = new RootPackage('llm/skills', '1.0.0.0', '1.0.0');

        Assert::false(SetupOffer::isNeeded(Path::create($this->tmp), $package));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function rootPackage(array $extra = []): RootPackage
    {
        $package = new RootPackage('demo/consumer', '1.0.0.0', '1.0.0');
        $package->setExtra($extra);

        return $package;
    }
}
