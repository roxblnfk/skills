<?php

declare(strict_types=1);

namespace LLM\Skills\Composer\Command;

use Composer\Command\BaseCommand;
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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Composer plugin entrypoint for `skills:add` (alias `skills:a`).
 *
 * Wires the {@see AddRunner} with a registry containing every
 * adapter (GitHub, GitLab) plus a real Composer-backed fetcher so
 * credentials in `auth.json` apply.
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
        $http = ComposerHttpClient::fromConfig($composer->getConfig());
        $registry = new HostAdapterRegistry(
            new GithubAdapter($http),
            new GitlabAdapter($http),
        );
        $fetcher = new HttpArchiveFetcher(
            $http,
            $projectRoot,
            CachePathBuilder::fromVendorDir($composer->getConfig()->get('vendor-dir')),
        );

        $runner = new AddRunner($registry, $fetcher);
        $donorPackageName = null;
        $exit = $runner->run(
            $projectRoot,
            $this->getIO(),
            $options,
            static function (SourceEntry $_entry, string $packageName) use (&$donorPackageName): void {
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
        return PostAddSync::run($projectRoot, $composer, $this->getIO(), $donorPackageName);
    }
}
