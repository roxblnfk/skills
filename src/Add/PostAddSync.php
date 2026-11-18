<?php

declare(strict_types=1);

namespace LLM\Skills\Add;

use Composer\Composer;
use Composer\IO\IOInterface;
use Internal\Path;
use LLM\Skills\Config\Mapper\ExternalProjectConfigLoader;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\VendorPattern;
use LLM\Skills\Discovery\Provider\DonorProviderBuilder;
use LLM\Skills\Filesystem\AtomicFileWriter;
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
 * Works with or without a Composer instance: the caller passes the root
 * `extra` it has — from the Composer root package, read from
 * `composer.json` directly, or `null` outside a project, which the
 * builder + mapper treat the same as an empty `extra.skills` block.
 *
 * @internal
 */
final class PostAddSync
{
    /**
     * @param mixed $extra raw `extra` of the root `composer.json`, or `null` without one
     * @param string|null $donorPackageName Composer-package name of the just-registered
     *        donor (read from the fetched composer.json's `name`, NOT from the CLI input —
     *        those can differ; e.g. GitHub's `<owner>/<repo>` path is unrelated to the
     *        package's `name`). Scopes the sync to that one donor; `null` widens to no filter.
     * @param string|false|null $configBefore contents of the config file the entry went to before it was
     *        registered, `null` when there was no such file, `false` when it could not be read.
     *        Restored when the sync aborts on a conflict with another donor: the entry would make
     *        every full run abort the same way.
     * @param non-empty-string $configFile the config file the entry went to, `skills.json` or
     *        `skills.local.json`
     */
    public static function run(
        Path $projectRoot,
        ?Composer $composer,
        mixed $extra,
        IOInterface $io,
        ?string $donorPackageName,
        string|false|null $configBefore,
        string $configFile = ExternalProjectConfigLoader::FILE_NAME,
    ): int {
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

        $restore = $configBefore === false ? null : static function () use ($projectRoot, $io, $configBefore, $configFile): void {
            $file = (string) $projectRoot->join($configFile);
            try {
                if ($configBefore === null) {
                    @\unlink($file);
                } else {
                    AtomicFileWriter::write($file, $configBefore);
                }
            } catch (\RuntimeException $e) {
                $io->writeError('<error>[llm/skills] could not restore ' . $configFile . ': ' . $e->getMessage() . '</error>');
                return;
            }
            $io->writeError(
                '<error>[llm/skills] the entry was not registered, as its skills conflict with another '
                . "donor's; " . $configFile . ' is as it was. Narrow it with --skill, or rename one of the skills.</error>',
            );
        };

        return (new SyncRunner())->run($projectRoot, $provider, $extra, $io, $syncOptions, $restore);
    }

    /**
     * The current contents of the config file `$configFile` for {@see self::run()}:
     * `null` when there is no such file, `false` when it cannot be read.
     *
     * @param non-empty-string $configFile
     */
    public static function snapshot(
        Path $projectRoot,
        string $configFile = ExternalProjectConfigLoader::FILE_NAME,
    ): string|false|null {
        $file = (string) $projectRoot->join($configFile);
        if (!\is_file($file)) {
            return null;
        }

        return @\file_get_contents($file);
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
