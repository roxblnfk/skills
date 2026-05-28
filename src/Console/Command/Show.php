<?php

declare(strict_types=1);

namespace LLM\Skills\Console\Command;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Internal\Path;
use LLM\Skills\Composer\ComposerJsonExtraReader;
use LLM\Skills\Console\ShowCliDefinition;
use LLM\Skills\Discovery\Provider\DonorProviderBuilder;
use LLM\Skills\Show\ShowRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Standalone `show` (alias `s`) for the `bin/skills` binary.
 *
 * Mirrors {@see \LLM\Skills\Composer\Command\Show} but bootstraps
 * Composer itself via {@see Factory::create()} on a best-effort
 * basis. When the current working directory has no `composer.json`
 * the {@see ComposerProvider} reports itself inactive and the runner
 * emits the `no donor providers are active` notice instead of dying
 * with a bootstrap error.
 *
 * @internal
 */
final class Show extends Command
{
    #[\Override]
    protected function configure(): void
    {
        ShowCliDefinition::apply($this, 'show', ['s']);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ConsoleIO($input, $output, new HelperSet([
            new QuestionHelper(),
        ]));

        try {
            $options = ShowCliDefinition::buildOptions($input);
        } catch (\InvalidArgumentException $e) {
            $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return self::INVALID;
        }

        $projectRoot = Path::create(\getcwd() ?: '.');
        $composer = self::tryBootstrapComposer($io);

        // See the twin in Console\Command\Sync — keeps the inline
        // `extra.skills` fallback usable when Composer bootstrap fails.
        /** @var mixed $extra */
        $extra = $composer !== null
            ? $composer->getPackage()->getExtra()
            : (new ComposerJsonExtraReader())->read($projectRoot, $io);

        $provider = (new DonorProviderBuilder())->build($projectRoot, $composer, $extra);

        return (new ShowRunner())->run(
            $projectRoot,
            $provider,
            $extra,
            $io,
            $options,
        );
    }

    /**
     * Best-effort {@see Factory::create()}. See the twin in
     * {@see \LLM\Skills\Console\Command\Sync} for the rationale.
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
                verbosity: IOInterface::VERBOSE,
            );
            return null;
        }

        try {
            return Factory::create($io, null, disablePlugins: true, disableScripts: true);
        } catch (\Throwable $e) {
            $io->writeError(
                '<comment>[warn] Composer bootstrap failed, continuing without it: '
                . $e->getMessage() . '</comment>',
                verbosity: IOInterface::VERBOSE,
            );
            return null;
        }
    }
}
