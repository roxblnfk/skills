<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\Exception\MalformedVendorConfig;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Config\Mapper\VendorConfigMapper;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\TrustedVendors;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Info;
use Symfony\Component\Console\Command\Command;

/**
 * Shared body of `skills:sync` — independent of which entrypoint invoked it.
 *
 * Two entrypoints share this runner:
 *
 * - {@see \LLM\Skills\Composer\Command\Sync} — wired into Composer via the
 *   plugin's {@see \LLM\Skills\Composer\CommandProvider}; the Composer
 *   instance is provided by `BaseCommand::requireComposer()`.
 * - {@see \LLM\Skills\Console\Command\Sync} — the standalone `bin/skills`
 *   binary; the Composer instance is bootstrapped manually via
 *   {@see \Composer\Factory::create()}.
 *
 * Whatever the source, the runner takes a ready-made {@see Composer}
 * instance plus an {@see IOInterface}, and goes through:
 *
 *   1. Map root `extra.skills` → {@see ProjectConfig}.
 *   2. Discover donors from `local-repository` (skip non-donors silently;
 *      surface malformed `extra.skills` blocks as `-v` warnings).
 *   3. Hand everything to {@see SyncPlanner} for trust/filter partitioning.
 *   4. Print skip notices, prompt or warn for untrusted-named donors.
 *   5. Run {@see SyncEngine} and format the {@see SyncReport}.
 *
 * Returns one of {@see Command::SUCCESS} / {@see Command::FAILURE} so both
 * entrypoints can return it as-is.
 */
final readonly class SyncRunner
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private SyncPlanner $planner = new SyncPlanner(),
        private SyncEngine $engine = new SyncEngine(),
        private VendorConfigMapper $vendorMapper = new VendorConfigMapper(),
        private ProjectConfigMapper $projectMapper = new ProjectConfigMapper(),
    ) {}

    public function run(Composer $composer, IOInterface $io, SyncOptions $options): int
    {
        try {
            $project = $this->projectMapper->fromExtra($composer->getPackage()->getExtra());
        } catch (MalformedProjectConfig $e) {
            $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $builtin = $this->loadBuiltinTrustedVendors();

        $donors = $this->discoverDonors($composer, $io);

        $projectRoot = Path::create(\getcwd() ?: '.');
        $plan = $this->planner->plan($donors, $project, $options, $builtin, $projectRoot);

        foreach ($plan->skippedUntrustedNames as $name) {
            $io->writeError(\sprintf(
                '<comment>[skip] %s is not in the trusted vendors list. '
                . 'Add it to extra.skills.trusted or rerun with --trust=%s.</comment>',
                $name,
                $name,
            ));
        }

        $approved = $this->resolveUntrustedNamed($plan->approvedDonors, $plan->untrustedNamedDonors, $io, $options);

        $report = $this->engine->sync($approved, $plan->target);

        foreach ($report->warnings as $warning) {
            $io->writeError('<comment>[warn] ' . $warning . '</comment>', verbosity: IOInterface::VERBOSE);
        }

        if ($report->hasConflicts()) {
            foreach ($report->conflicts as $conflict) {
                $io->writeError(\sprintf(
                    '<error>[conflict] skill "%s" declared by: %s</error>',
                    $conflict->name,
                    \implode(', ', $conflict->packages),
                ));
            }
            $io->writeError('<error>Sync aborted due to skill-name conflicts; nothing was written.</error>');
            return Command::FAILURE;
        }

        foreach ($report->copied as $skill) {
            $io->write(\sprintf(
                '<info>[copy]</info> %s ← %s',
                $skill->name,
                $skill->packageName,
            ));
        }

        $io->write(\sprintf(
            '<info>[llm/skills] synced %d skill(s) into %s</info>',
            \count($report->copied),
            (string) $plan->target,
        ));

        return Command::SUCCESS;
    }

    /**
     * Build the list of donor packages from Composer's local repository. Skips
     * non-donors silently; broken `extra.skills` blocks are surfaced as `-v`
     * warnings and the offending package is dropped.
     *
     * @return list<VendorConfig>
     */
    private function discoverDonors(Composer $composer, IOInterface $io): array
    {
        $donors = [];

        /** @var PackageInterface $package */
        foreach ($composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $extra = $package->getExtra();
            if (!VendorConfigMapper::declaresSkills($extra)) {
                continue;
            }

            $installPath = $composer->getInstallationManager()->getInstallPath($package);
            if ($installPath === null) {
                $io->writeError(
                    \sprintf('<comment>[warn] %s: install path unavailable</comment>', $package->getName()),
                    verbosity: IOInterface::VERBOSE,
                );
                continue;
            }

            /** @var non-empty-string $name */
            $name = $package->getName();

            try {
                $donors[] = $this->vendorMapper->fromExtra($name, Path::create($installPath), $extra);
            } catch (MalformedVendorConfig $e) {
                $io->writeError(
                    '<comment>[warn] ' . $e->getMessage() . '</comment>',
                    verbosity: IOInterface::VERBOSE,
                );
            }
        }

        return $donors;
    }

    /**
     * Resolve donors that were named on the CLI but are not trusted: in
     * interactive mode we prompt (default Yes), otherwise we warn and proceed.
     *
     * @param list<VendorConfig> $approved       starting set (already-trusted donors)
     * @param list<VendorConfig> $untrustedNamed donors needing a confirmation
     *
     * @return list<VendorConfig>
     */
    private function resolveUntrustedNamed(
        array $approved,
        array $untrustedNamed,
        IOInterface $io,
        SyncOptions $options,
    ): array {
        foreach ($untrustedNamed as $donor) {
            if ($options->interactive && $io->isInteractive()) {
                $confirmed = $io->askConfirmation(
                    \sprintf('<question>%s is not trusted. Sync anyway?</question> [Y/n] ', $donor->packageName),
                    true,
                );
                if ($confirmed) {
                    $approved[] = $donor;
                }
                continue;
            }

            $io->writeError(\sprintf(
                '<comment>[warn] %s is not trusted; syncing anyway because explicitly named.</comment>',
                $donor->packageName,
            ));
            $approved[] = $donor;
        }

        return $approved;
    }

    /**
     * @psalm-suppress MissingPureAnnotation `require` is conceptually pure here
     *                 (the file is shipped with the package) but psalm cannot prove it.
     *
     * @psalm-pure
     */
    private function loadBuiltinTrustedVendors(): TrustedVendors
    {
        /**
         * @var list<non-empty-string> $patterns
         *
         * @psalm-suppress UnresolvableInclude
         */
        $patterns = require Info::ROOT_DIR . '/resources/trusted-vendors.php';

        return TrustedVendors::fromStrings(...$patterns);
    }
}
