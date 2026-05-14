<?php

declare(strict_types=1);

namespace LLM\Skills\Console\Command;

use Composer\Factory;
use Composer\IO\ConsoleIO;
use LLM\Skills\Console\ShowCliDefinition;
use LLM\Skills\Show\ShowRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Standalone `show` (alias `s`) for the `bin/skills` binary.
 *
 * Mirrors {@see \LLM\Skills\Composer\Command\Show} but bootstraps
 * Composer itself via {@see Factory::create()} because there is no
 * Composer process around to inject one. Plugins are disabled during
 * bootstrap for the same reason as the standalone `update`: we never
 * want this binary to wake up another `llm/skills` plugin instance.
 *
 * @internal
 */
final class Show extends Command
{
    #[\Override]
    protected function configure(): void
    {
        ShowCliDefinition::apply($this, 'show', ['s']);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ConsoleIO($input, $output, new HelperSet([
            new QuestionHelper(),
        ]));

        try {
            $options = ShowCliDefinition::buildOptions($input);
        } catch (\InvalidArgumentException $e) {
            $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return self::INVALID;
        }

        try {
            $composer = Factory::create($io, null, disablePlugins: true, disableScripts: true);
        } catch (\Throwable $e) {
            $io->writeError('<error>[llm/skills] Failed to bootstrap Composer: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }

        return (new ShowRunner())->run($composer, $io, $options);
    }
}
