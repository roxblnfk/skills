<?php

declare(strict_types=1);

namespace LLM\Skills\Add;

use Composer\Composer;
use Composer\IO\IOInterface;
use Internal\Path;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\VendorPattern;
use LLM\Skills\Discovery\Provider\DonorProviderBuilder;
use LLM\Skills\Sync\SyncRunner;

/**
 * The scoped single-donor sync that runs after a successful
 * `skills:add`, so the just-registered skills land in the target
 * immediately — matching `composer require`'s "edit + install"
 * ergonomics.
 *
 * Shared by both entrypoints ({@see \LLM\Skills\Composer\Command\Add}
 * and {@see \LLM\Skills\Console\Command\Add}) so the wiring lives in one
 * place instead of two copies that could drift.
 *
 * Works with or without a Composer instance: the standalone bin invoked
 * outside a project passes `null`, which the builder + mapper treat the
 * same as an empty `extra.skills` block — only the just-added remote
 * donor syncs, which is exactly what a `skills:add` invocation means.
 *
 * @internal
 */
final class PostAddSync
{
    /**
     * @param string|null $donorPackageName Composer-package name of the just-registered
     *        donor (read from the fetched composer.json's `name`, NOT from the CLI input —
     *        those can differ; e.g. GitHub's `<owner>/<repo>` path is unrelated to the
     *        package's `name`). Scopes the sync to that one donor; `null` widens to no filter.
     */
    public static function run(
        Path $projectRoot,
        ?Composer $composer,
        IOInterface $io,
        ?string $donorPackageName,
    ): int {
        /** @var mixed $extra */
        $extra = $composer?->getPackage()->getExtra();
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
     * Single-element `packageFilters` scoped to the donor's
     * Composer-package name. `null` / empty widens to no filter (the
     * providers' full donor set syncs).
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
