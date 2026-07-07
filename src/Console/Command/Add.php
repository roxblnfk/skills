<?php

declare(strict_types=1);

namespace LLM\Skills\Console\Command;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\ConsoleIO;
use Composer\IO\NullIO;
use Internal\Path;
use LLM\Skills\Add\AddRunner;
use LLM\Skills\Add\PostAddSync;
use LLM\Skills\Config\SourceEntry;
use LLM\Skills\Console\AddCliDefinition;
use LLM\Skills\Discovery\Provider\Remote\Adapter\GithubAdapter;
use LLM\Skills\Discovery\Provider\Remote\Adapter\GitlabAdapter;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Remote\CachePathBuilder;
use LLM\Skills\Discovery\Provider\Remote\Http\ComposerHttpClient;
use LLM\Skills\Discovery\Provider\Remote\HttpArchiveFetcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Standalone `add` (alias `a`) for the `bin/skills` binary.
 *
 * Bootstraps Composer when a `composer.json` is present so the user's
 * project `auth.json` and any custom `vendor-dir` apply. When there is
 * no `composer.json` at the working directory the command still runs:
 * the user's global `~/.composer/auth.json` is loaded via
 * {@see Factory::createConfig()}, the cache lives under
 * `<cwd>/vendor/llm-skills/cache` (the default layout), and the new
 * entry is upserted into `<cwd>/skills.json`. Composer-local donors
 * stay inactive in that mode — only the just-added donor source
 * syncs, which is exactly what a `skills:add` invocation means.
 *
 * @internal
 */
final class Add extends Command
{
    #[\Override]
    protected function configure(): void
    {
        AddCliDefinition::apply($this, 'add', ['a']);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new ConsoleIO($input, $output, new HelperSet([new QuestionHelper()]));

        try {
            $options = AddCliDefinition::buildOptions($input);
        } catch (\InvalidArgumentException $e) {
            $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return self::INVALID;
        }

        $projectRoot = Path::create(\getcwd() ?: '.');
        $composer = self::tryBootstrapComposer($io);

        // HTTP client: prefer the project's Composer config (its
        // `auth.json` + `vendor-dir` overrides apply), otherwise fall
        // back to a default Config built via `Factory::createConfig()`,
        // which still loads the user-wide `~/.composer/auth.json`.
        $config = $composer?->getConfig() ?? Factory::createConfig(new NullIO());
        $http = ComposerHttpClient::fromConfig($config);
        $registry = new HostAdapterRegistry(
            new GithubAdapter($http),
            new GitlabAdapter($http),
        );
        $fetcher = new HttpArchiveFetcher(
            $http,
            $projectRoot,
            CachePathBuilder::fromVendorDir($config->get('vendor-dir')),
        );

        $runner = new AddRunner($registry, $fetcher);
        $donorPackageName = null;
        $exit = $runner->run(
            $projectRoot,
            $io,
            $options,
            static function (SourceEntry $_entry, string $packageName) use (&$donorPackageName): void {
                $donorPackageName = $packageName;
            },
        );

        if ($exit !== self::SUCCESS || !$options->sync) {
            return $exit;
        }

        // Without a Composer instance there is no root package to read an
        // `extra` from — PostAddSync falls through with `null`, which the
        // mapper treats the same as an empty `extra.skills` block. Only
        // the just-registered donor source will sync.
        return PostAddSync::run($projectRoot, $composer, $io, $donorPackageName);
    }

    /**
     * Best-effort {@see Factory::create()}. Returns `null` when there is
     * no `composer.json` at the working directory or when bootstrap
     * fails — both cases are handled by the caller falling back to a
     * default {@see Config} for HTTP plumbing.
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
