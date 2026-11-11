<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Console;

use LLM\Skills\Console\FlagPair;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FlagPair::class)]
final class FlagPairTest
{
    #[DataSet([[], null], 'neither')]
    #[DataSet([['--on' => true], true], 'positive')]
    #[DataSet([['--no-on' => true], false], 'negative')]
    #[DataSet([['--on' => true, '--no-on' => true], false], 'both')]
    public function negativeWins(array $flags, ?bool $expected): void
    {
        Assert::same(FlagPair::negativeWins($this->input($flags), 'on', 'no-on'), $expected);
    }

    #[DataSet([[], null], 'neither')]
    #[DataSet([['--on' => true], true], 'positive')]
    #[DataSet([['--no-on' => true], false], 'negative')]
    public function exclusive(array $flags, ?bool $expected): void
    {
        Assert::same(FlagPair::exclusive($this->input($flags), 'on', 'no-on'), $expected);
    }

    public function exclusiveRejectsBoth(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('--on and --no-on contradict each other');

        FlagPair::exclusive($this->input(['--on' => true, '--no-on' => true]), 'on', 'no-on');
    }

    /**
     * @param array<string, bool> $flags
     */
    private function input(array $flags): ArrayInput
    {
        return new ArrayInput($flags, new InputDefinition([
            new InputOption('on', null, InputOption::VALUE_NONE),
            new InputOption('no-on', null, InputOption::VALUE_NONE),
        ]));
    }
}
