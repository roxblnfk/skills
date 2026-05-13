<?php

declare(strict_types=1);

namespace LLM\Skills\Composer\Command;

use Composer\Command\BaseCommand;
use LLM\Skills\Console\SyncCliDefinition;
use LLM\Skills\Sync\SyncRunner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Composer plugin entrypoint for `skills:sync`. Registered by
 * {@see \LLM\Skills\Composer\CommandProvider}.
 *
 * Composer hands us a fully-bootstrapped {@see \Composer\Composer} via
 * {@see BaseCommand::requireComposer()} and an {@see \Composer\IO\IOInterface}
 * via {@see BaseCommand::getIO()}. Everything else (CLI parsing, business
 * logic) lives in shared classes — this file is intentionally just glue.
 *
 * For the standalone-binary entrypoint that does not run inside Composer,
 * see {@see \LLM\Skills\Console\Command\Sync}.
 *
 * @internal
 */
final class Sync extends BaseCommand
{
    #[\Override]
    protected function configure(): void
    {
        SyncCliDefinition::apply($this, 'skills:sync');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $options = SyncCliDefinition::buildOptions($input);
        } catch (\InvalidArgumentException $e) {
            $this->getIO()->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return self::INVALID;
        }

        return (new SyncRunner())->run($this->requireComposer(), $this->getIO(), $options);
    }
}
