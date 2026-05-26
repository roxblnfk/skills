<?php

declare(strict_types=1);

namespace LLM\Skills\Composer\Command;

use Composer\Command\BaseCommand;
use Composer\IO\NullIO;
use Composer\Util\HttpDownloader;
use Internal\Path;
use LLM\Skills\Add\AddRunner;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Console\AddCliDefinition;
use LLM\Skills\Discovery\Provider\Remote\Adapter\GithubAdapter;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapterRegistry;
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
 * `composer require` "edit + install" ergonomics described in spec
 * §6.1 step 6.
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
        $fetcher = new HttpArchiveFetcher($http, $projectRoot);

        $runner = new AddRunner($registry, $fetcher);
        $exit = $runner->run($projectRoot, $this->getIO(), $options);

        if ($exit !== self::SUCCESS || !$options->sync) {
            return $exit;
        }

        // Spec §6.1 step 6: drop straight into a sync so the newly
        // registered remote donor's skills land in the target. We
        // intentionally use the full provider chain (local +
        // remote) so the user sees the same view they'd see if they
        // ran `skills:update` themselves.
        $extra = $composer->getPackage()->getExtra();
        $provider = (new DonorProviderBuilder())->build($projectRoot, $composer, $extra);

        $syncOptions = new SyncOptions(
            packageFilters: [],
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
}
