<?php

declare(strict_types=1);

namespace LLM\Skills\Console\Command;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\ConsoleIO;
use Internal\Path;
use LLM\Skills\Console\SyncCliDefinition;
use LLM\Skills\Discovery\Provider\ComposerProvider;
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
 * try to bootstrap one ourselves via {@see Factory::create()}.
 *
 * The bootstrap is **best-effort**: if no `composer.json` is found at the
 * current working directory, the {@see ComposerProvider} simply reports
 * itself inactive and the runner emits the `[no donors available]`
 * notice. Future providers (GitHub, npm, skills.sh, …) will plug into
 * the same provider chain, so a Composer-less project will still have
 * actionable behaviour once those land. For now standalone mode is a
 * benign no-op rather than a crash.
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

        $composer = self::tryBootstrapComposer($io);
        $provider = new ComposerProvider($composer);
        $projectRoot = Path::create(\getcwd() ?: '.');

        return (new SyncRunner())->run(
            $projectRoot,
            $provider,
            $provider->rootExtras(),
            $io,
            $options,
        );
    }

    /**
     * Best-effort {@see Factory::create()}. Returns `null` when the
     * working directory has no `composer.json` (so the runner takes the
     * standalone path) or when bootstrap fails for any other reason.
     *
     * Diagnostic output for non-trivial failures is intentionally
     * limited to a `-v` line: a missing `composer.json` is the
     * documented standalone case, not an error, so a noisy banner
     * would just confuse users running `skills` outside any Composer
     * project.
     */
    private static function tryBootstrapComposer(ConsoleIO $io): ?Composer
    {
        $cwd = \getcwd() ?: '.';
        if (!\is_file($cwd . '/composer.json')) {
            $io->writeError(
                \sprintf(
                    '<comment>[warn] no composer.json at %s — Composer donor provider is inactive.</comment>',
                    $cwd,
                ),
                verbosity: \Composer\IO\IOInterface::VERBOSE,
            );
            return null;
        }

        try {
            return Factory::create($io, null, disablePlugins: true, disableScripts: true);
        } catch (\Throwable $e) {
            $io->writeError(
                '<comment>[warn] Composer bootstrap failed, continuing without it: '
                . $e->getMessage() . '</comment>',
                verbosity: \Composer\IO\IOInterface::VERBOSE,
            );
            return null;
        }
    }
}
