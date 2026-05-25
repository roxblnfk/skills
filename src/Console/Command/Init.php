<?php

declare(strict_types=1);

namespace LLM\Skills\Console\Command;

use Composer\IO\ConsoleIO;
use Internal\Path;
use LLM\Skills\Console\InitCliDefinition;
use LLM\Skills\Init\InitRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Standalone `init` (alias `i`) for the `bin/skills` binary.
 *
 * Unlike {@see \LLM\Skills\Composer\Command\Init}, this entrypoint is
 * *not* invoked through `composer` and does not require a Composer
 * instance — `init` only reads `composer.json` if it exists at the
 * current working directory, and writes plain JSON. Bootstrapping
 * Composer here would be unnecessary overhead.
 *
 * @internal
 */
final class Init extends Command
{
    #[\Override]
    protected function configure(): void
    {
        InitCliDefinition::apply($this, 'init', ['i']);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ConsoleIO($input, $output, new HelperSet([
            new QuestionHelper(),
        ]));

        try {
            $options = InitCliDefinition::buildOptions($input);
        } catch (\InvalidArgumentException $e) {
            $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return self::INVALID;
        }

        $projectRoot = Path::create(\getcwd() ?: '.');

        return (new InitRunner())->run($projectRoot, $io, $options);
    }
}
