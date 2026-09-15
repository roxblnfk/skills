<?php

declare(strict_types=1);

namespace LLM\Skills\Init;

use Composer\Composer;
use Composer\IO\IOInterface;
use Internal\Path;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Discovery\Provider\DonorProviderBuilder;
use LLM\Skills\Sync\SyncRunner;

/**
 * The full sync that runs after `skills:init` wrote a configuration, so
 * the project ends up with skills on disk rather than with a config file
 * and a second command to remember.
 *
 * Shared by both entrypoints ({@see \LLM\Skills\Composer\Command\Init}
 * and {@see \LLM\Skills\Console\Command\Init}) so the wiring lives in one
 * place. The sibling {@see \LLM\Skills\Add\PostAddSync} does the same for
 * `skills:add`, scoped to the one donor that command registered; init has
 * no such scope, so every donor the fresh config allows is synced.
 *
 * Works with or without a Composer instance: the standalone bin invoked
 * outside a project passes `null`, and only the config's own `sources[]`
 * contribute donors.
 *
 * @internal
 */
final class PostInitSync
{
    public static function run(Path $projectRoot, ?Composer $composer, IOInterface $io): int
    {
        /** @var mixed $extra */
        $extra = $composer?->getPackage()->getExtra();
        $provider = (new DonorProviderBuilder())->build($projectRoot, $composer, $extra);

        $syncOptions = new SyncOptions(
            packageFilters: [],
            extraTrusted: [],
            targetOverride: null,
            interactive: false,
            dryRun: false,
            discovery: null,
            aliasOverrides: null,
            // The config was just written from the inline block, if there
            // was one; there is nothing left for a migration to move.
            autoMigrate: false,
        );

        return (new SyncRunner())->run($projectRoot, $provider, $extra, $io, $syncOptions);
    }
}
