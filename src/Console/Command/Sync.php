<?php

declare(strict_types=1);

namespace LLM\Skills\Console\Command;

use Composer\Factory;
use Composer\IO\ConsoleIO;
use LLM\Skills\Console\SyncCliDefinition;
use LLM\Skills\Sync\SyncRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Standalone `update` (alias `u`) for the `bin/skills` binary.
 *
 * Unlike {@see \LLM\Skills\Composer\Command\Sync}, this entrypoint is *not*
 * invoked through `composer` and has no implicit Composer instance — we
 * bootstrap one ourselves via {@see Factory::create()}. The bootstrap reads
 * the current working directory's `composer.json`/`composer.lock`, so the
 * binary must be invoked from inside a Composer project (the same place
 * `composer install` would work).
 *
 * `--disable-plugins` is set: when run from a global PHAR we have no reason
 * to load and execute third-party plugins, and we never want this binary to
 * recursively wake up another `llm/skills` plugin instance in the host
 * project.
 *
 * @internal
 */
final class Sync extends Command
{
    #[\Override]
    protected function configure(): void
    {
        SyncCliDefinition::apply($this, 'update', ['u']);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ConsoleIO($input, $output, new HelperSet([
            new \Symfony\Component\Console\Helper\QuestionHelper(),
        ]));

        try {
            $options = SyncCliDefinition::buildOptions($input);
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

        return (new SyncRunner())->run($composer, $io, $options);
    }
}
