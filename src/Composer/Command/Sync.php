<?php

declare(strict_types=1);

namespace LLM\Skills\Composer\Command;

use Composer\Command\BaseCommand;
use Internal\Path;
use LLM\Skills\Console\SyncCliDefinition;
use LLM\Skills\Discovery\Provider\DonorProviderBuilder;
use LLM\Skills\Sync\SyncRunner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Composer plugin entrypoint for `skills:update` (alias `skills:u`).
 * Registered by {@see \LLM\Skills\Composer\CommandProvider}.
 *
 * Composer hands us a fully-bootstrapped {@see \Composer\Composer} via
 * {@see BaseCommand::requireComposer()} and an {@see \Composer\IO\IOInterface}
 * via {@see BaseCommand::getIO()}. Everything else (CLI parsing, the sync
 * pipeline) lives in shared classes — this file is intentionally just glue.
 *
 * For the PHAR/binary entrypoint that bootstraps Composer manually, see
 * {@see \LLM\Skills\Console\Command\Sync}.
 *
 * @internal
 */
final class Sync extends BaseCommand
{
    #[\Override]
    protected function configure(): void
    {
        SyncCliDefinition::apply($this, 'skills:update', ['skills:u'], discoveryShortFlag: false);
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

        $composer = $this->requireComposer();
        $projectRoot = Path::create(\getcwd() ?: '.');
        $extra = $composer->getPackage()->getExtra();
        $provider = (new DonorProviderBuilder())->build($projectRoot, $composer, $extra);

        return (new SyncRunner())->run(
            $projectRoot,
            $provider,
            $extra,
            $this->getIO(),
            $options,
        );
    }
}
