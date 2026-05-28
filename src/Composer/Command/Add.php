<?php

declare(strict_types=1);

namespace LLM\Skills\Composer\Command;

use Composer\Command\BaseCommand;
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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Composer plugin entrypoint for `skills:add` (alias `skills:a`).
 *
 * Wires the {@see AddRunner} with a registry containing every v1
 * adapter (currently just GitHub) plus a real Composer-backed
 * fetcher so credentials in `auth.json` apply.
 *
 * After the runner returns SUCCESS, the entrypoint optionally
 * triggers a single-entry sync (via {@see SyncRunner}) so the new
 * skills land in the target immediately — matching the
 * `composer require` "edit + install" ergonomics.
 *
 * @internal
 */
final class Add extends BaseCommand
{
    #[\Override]
    protected function configure(): void
    {
        AddCliDefinition::apply($this, 'skills:add', ['skills:a']);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $options = AddCliDefinition::buildOptions($input);
        } catch (\InvalidArgumentException $e) {
            $this->getIO()->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return self::INVALID;
        }

        $composer = $this->requireComposer();
        $projectRoot = Path::create(\getcwd() ?: '.');
        $http = new ComposerHttpClient(new HttpDownloader(new NullIO(), $composer->getConfig()));
        $registry = new HostAdapterRegistry(new GithubAdapter($http));
        $fetcher = new HttpArchiveFetcher($http, $projectRoot, self::cacheBuilderFor($composer));

        $runner = new AddRunner($registry, $fetcher);
        $donorPackageName = null;
        $exit = $runner->run(
            $projectRoot,
            $this->getIO(),
            $options,
            static function (RemoteEntry $_entry, string $packageName) use (&$donorPackageName): void {
                $donorPackageName = $packageName;
            },
        );

        if ($exit !== self::SUCCESS || !$options->sync) {
            return $exit;
        }

        // Drop into a sync scoped to the just-registered donor so the
        // new skills land in the target without re-syncing everything
        // else. Matches `composer require <pkg>` ergonomics, which
        // only installs the requested package's tree.
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
        return (new SyncRunner())->run($projectRoot, $provider, $extra, $this->getIO(), $syncOptions);
    }

    /**
     * Honour the project's configured `vendor-dir` when computing the
     * cache layout. Without this, a `vendor-dir: "deps"` project would
     * see cached archives written under `vendor/` (which Composer may
     * not even use, and which `composer install` doesn't gitignore).
     */
    private static function cacheBuilderFor(\Composer\Composer $composer): CachePathBuilder
    {
        /** @var mixed $vendorDir */
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        if (!\is_string($vendorDir) || $vendorDir === '') {
            return new CachePathBuilder();
        }
        return new CachePathBuilder(Path::create($vendorDir)->join('llm-skills/cache'));
    }

    /**
     * Single-element `packageFilters` scoped to the donor's
     * Composer-package name (read from the fetched composer.json's
     * `name`, NOT from `$options->input` — those can differ; e.g.
     * GitHub's `<owner>/<repo>` path is unrelated to the package's
     * `name`).
     *
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
}
