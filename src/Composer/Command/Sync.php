<?php

declare(strict_types=1);

namespace LLM\Skills\Composer\Command;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
final class Sync extends BaseCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('skills:sync')
            ->setDescription('Sync AI skills from vendor packages into the project')
            ->addOption(
                'target',
                't',
                InputOption::VALUE_REQUIRED,
                'Destination directory for synced skills',
                '.claude/skills',
            )
            ->addOption(
                'override',
                'o',
                InputOption::VALUE_NONE,
                'Overwrite existing files in the destination',
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();
        $io = $this->getIO();

        $io->write('<info>skills:sync — scaffold, not implemented yet.</info>');

        // TODO:
        // 1. Discover installed packages via $composer->getRepositoryManager()
        //    ->getLocalRepository()->getPackages()
        // 2. For each package, read its `extra.skills` block (folder-based or explicit)
        // 3. Resolve source paths via $composer->getInstallationManager()
        //    ->getInstallPath($package)
        // 4. Copy skill files to the target directory respecting --override

        return self::SUCCESS;
    }
}
