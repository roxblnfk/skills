<?php

declare(strict_types=1);

namespace LLM\Skills\Composer\Command;

use Composer\Command\BaseCommand;
use LLM\Skills\Console\LintCliDefinition;
use LLM\Skills\Validation\LintRunner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Composer plugin entrypoint for `skills:lint`.
 * Registered by {@see \LLM\Skills\Composer\CommandProvider}.
 *
 * Validates arbitrary skill directories; it reads no project configuration.
 * For the binary entrypoint see {@see \LLM\Skills\Console\Command\Lint}.
 *
 * @internal
 */
final class Lint extends BaseCommand
{
    #[\Override]
    protected function configure(): void
    {
        LintCliDefinition::apply($this, 'skills:lint');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Composer may have moved to a parent project's root before the command runs;
        // paths the user typed are relative to where they stood.
        $application = $this->getApplication();
        /**
         * @psalm-suppress RedundantCondition, TypeDoesNotContainType the Composer running the plugin
         *         may be older than the one analysed, from before the method existed
         */
        $cwd = \method_exists($application, 'getInitialWorkingDirectory')
            ? $application->getInitialWorkingDirectory()
            : null;

        return (new LintRunner())->run(
            \is_string($cwd) && $cwd !== '' ? $cwd : (\getcwd() ?: '.'),
            LintCliDefinition::paths($input),
            LintCliDefinition::json($input),
            $output,
        );
    }
}
