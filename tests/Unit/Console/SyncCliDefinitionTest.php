<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Console;

use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Console\SyncCliDefinition;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Verifies how {@see SyncCliDefinition} turns `skills:update` flags into
 * {@see SyncOptions}.
 */
#[Test]
#[Covers(SyncCliDefinition::class)]
final class SyncCliDefinitionTest
{
    public function cleanDefersToSkillsJsonWhenNeitherFlagIsGiven(): void
    {
        Assert::null($this->build([])->clean);
    }

    public function cleanFlagForcesTheWipe(): void
    {
        Assert::true($this->build(['--clean' => true])->clean);
    }

    public function noCleanFlagForcesTheMerge(): void
    {
        Assert::false($this->build(['--no-clean' => true])->clean);
    }

    public function noCleanWinsWhenBothFlagsAreGiven(): void
    {
        // A merge cannot delete anything, so it is the safe reading.
        Assert::false($this->build(['--clean' => true, '--no-clean' => true])->clean);
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private function build(array $inputs): SyncOptions
    {
        $cmd = new Command();
        SyncCliDefinition::apply($cmd, 'skills:update');

        return SyncCliDefinition::buildOptions(new ArrayInput($inputs, $cmd->getDefinition()));
    }
}
