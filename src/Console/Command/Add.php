<?php

declare(strict_types=1);

namespace LLM\Skills\Console\Command;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\ConsoleIO;
use Composer\IO\NullIO;
use Composer\Util\HttpDownloader;
use Internal\Path;
use LLM\Skills\Add\AddRunner;
use LLM\Skills\Config\RemoteEntry;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\VendorPattern;
use LLM\Skills\Console\AddCliDefinition;
use LLM\Skills\Discovery\Provider\Remote\Adapter\GithubAdapter;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Remote\CachePathBuilder;
use LLM\Skills\Discovery\Provider\Remote\Http\ComposerHttpClient;
use LLM\Skills\Discovery\Provider\Remote\HttpArchiveFetcher;
use LLM\Skills\Discovery\Provider\DonorProviderBuilder;
use LLM\Skills\Sync\SyncRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Standalone `add` (alias `a`) for the `bin/skills` binary.
 *
 * Bootstraps Composer manually via {@see Factory::create()} to
 * inherit `auth.json` credentials. When no `composer.json` exists at
 * the working directory, the command refuses — `skills:add` is a
 * write command and we will not silently store entries in a
 * directory we cannot also sync from.
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
        if ($composer === null) {
            $io->writeError(
                '<error>[llm/skills] skills:add requires a composer.json at the current '
                . 'directory (used for HTTP auth + cache layout). Run from a project root.</error>',
            );
            return self::FAILURE;
        }

        $http = new ComposerHttpClient(new HttpDownloader(new NullIO(), $composer->getConfig()));
        $registry = new HostAdapterRegistry(new GithubAdapter($http));
        $fetcher = new HttpArchiveFetcher($http, $projectRoot, self::cacheBuilderFor($composer));

        $runner = new AddRunner($registry, $fetcher);
        $donorPackageName = null;
        $exit = $runner->run(
            $projectRoot,
            $io,
            $options,
            static function (RemoteEntry $_entry, string $packageName) use (&$donorPackageName): void {
                $donorPackageName = $packageName;
            },
        );

        if ($exit !== self::SUCCESS || !$options->sync) {
            return $exit;
        }

        /** @var mixed $extra */
        $extra = $composer->getPackage()->getExtra();
        $provider = (new DonorProviderBuilder())->build($projectRoot, $composer, $extra);
        $syncOptions = new SyncOptions(
            packageFilters: self::filterFor($donorPackageName),
            extraTrusted: [],
            targetOverride: null,
            interactive: false,
            dryRun: false,
            discovery: null,
            aliasOverrides: null,
            autoMigrate: false,
        );
        return (new SyncRunner())->run($projectRoot, $provider, $extra, $io, $syncOptions);
    }

    /**
     * Honour the project's configured `vendor-dir` so a custom value
     * doesn't leave the cache under an unused `vendor/` directory.
     */
    private static function cacheBuilderFor(Composer $composer): CachePathBuilder
    {
        /** @var mixed $vendorDir */
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        if (!\is_string($vendorDir) || $vendorDir === '') {
            return new CachePathBuilder();
        }
        return new CachePathBuilder(Path::create($vendorDir)->join('llm-skills/cache'));
    }

    /**
     * @return list<VendorPattern>
     *
     * @psalm-pure
     */
    private static function filterFor(?string $donorPackageName): array
    {
        if ($donorPackageName === null || $donorPackageName === '') {
            return [];
        }
        return [VendorPattern::fromString($donorPackageName)];
    }

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
