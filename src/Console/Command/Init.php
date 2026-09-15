<?php

declare(strict_types=1);

namespace LLM\Skills\Console\Command;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\ConsoleIO;
use Internal\Path;
use LLM\Skills\Console\InitCliDefinition;
use LLM\Skills\Init\InitRunner;
use LLM\Skills\Init\PostInitSync;
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
 * Composer is bootstrapped only for the follow-up sync, and only when
 * there is a `composer.json` to bootstrap it from: without one, the
 * project's own `sources[]` are the only donors, and the sync needs no
 * Composer at all.
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

        $written = false;
        $exit = (new InitRunner())->run(
            $projectRoot,
            $io,
            $options,
            static function () use (&$written): void {
                $written = true;
            },
        );

        if ($exit !== self::SUCCESS || !$written || !$options->sync) {
            return $exit;
        }

        return PostInitSync::run($projectRoot, self::tryBootstrapComposer($io), $io);
    }

    /**
     * Best-effort {@see Factory::create()}. Returns `null` when there is
     * no `composer.json` at the working directory or when bootstrap
     * fails — the sync then sees no Composer-local donors, which is the
     * same state a project without Composer is in anyway.
     */
    private static function tryBootstrapComposer(ConsoleIO $io): ?Composer
    {
        $cwd = \getcwd() ?: '.';
        if (!\is_file($cwd . '/composer.json')) {
            return null;
        }
        try {
            return Factory::create($io, null, disablePlugins: true, disableScripts: true);
        } catch (\Throwable $e) {
            $io->writeError(
                '<comment>[warn] Composer bootstrap failed: ' . $e->getMessage() . '</comment>',
            );
            return null;
        }
    }
}
