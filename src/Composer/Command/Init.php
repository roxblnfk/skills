<?php

declare(strict_types=1);

namespace LLM\Skills\Composer\Command;

use Composer\Command\BaseCommand;
use Internal\Path;
use LLM\Skills\Console\InitCliDefinition;
use LLM\Skills\Init\InitRunner;
use LLM\Skills\Init\PostInitSync;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Composer plugin entrypoint for `skills:init` (alias `skills:i`).
 * Registered by {@see \LLM\Skills\Composer\CommandProvider}.
 *
 * Creates a `skills.json` file at the project root and, when a
 * `composer.json` is present, migrates the inline `extra.skills`
 * project keys into it (donor `extra.skills.source` is left in place).
 *
 * A written config is followed by a full sync, so the command leaves the
 * project with skills rather than with a config file and a second command
 * to remember. `--no-sync` opts out.
 *
 * For the PHAR/binary entrypoint that bootstraps Composer manually,
 * see {@see \LLM\Skills\Console\Command\Init}.
 *
 * @internal
 */
final class Init extends BaseCommand
{
    #[\Override]
    protected function configure(): void
    {
        InitCliDefinition::apply($this, 'skills:init', ['skills:i']);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $options = InitCliDefinition::buildOptions($input);
        } catch (\InvalidArgumentException $e) {
            $this->getIO()->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return self::INVALID;
        }

        $projectRoot = Path::create(\getcwd() ?: '.');

        $written = false;
        $exit = (new InitRunner())->run(
            $projectRoot,
            $this->getIO(),
            $options,
            static function () use (&$written): void {
                $written = true;
            },
        );

        if ($exit !== self::SUCCESS || !$written || !$options->sync) {
            return $exit;
        }

        return PostInitSync::run($projectRoot, $this->tryComposer(), $this->getIO());
    }
}
