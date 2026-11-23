<?php

declare(strict_types=1);

namespace LLM\Skills\Console\Command;

use LLM\Skills\Console\LintCliDefinition;
use LLM\Skills\Validation\LintRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Standalone `lint` for the `bin/skills` binary. Needs no Composer project:
 * it validates whatever paths it is given.
 *
 * @internal
 */
final class Lint extends Command
{
    #[\Override]
    protected function configure(): void
    {
        LintCliDefinition::apply($this, 'lint');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (new LintRunner())->run(
            \getcwd() ?: '.',
            LintCliDefinition::paths($input),
            LintCliDefinition::json($input),
            $output,
        );
    }
}
