<?php

declare(strict_types=1);

namespace LLM\Skills\Composer\Command;

use Composer\Command\BaseCommand;
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
use LLM\Skills\Config\VendorPattern;
use LLM\Skills\Info;
use LLM\Skills\Sync\SyncEngine;
use LLM\Skills\Sync\SyncPlanner;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer skills:sync` — copy AI skills from installed Composer packages
 * into a project-local directory.
 *
 * The command is a thin glue layer: it parses CLI input, walks Composer's
 * package list, hands the curated data to {@see SyncPlanner} (trust/filter
 * decisions) and {@see SyncEngine} (actual copy), and formats the result.
 *
 * @internal
 */
final class Sync extends BaseCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('skills:sync')
            ->setDescription('Sync AI skills from vendor packages into the project')
            ->addArgument(
                'packages',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Restrict sync to matching packages (exact "vendor/pkg" or wildcard "vendor/*"). '
                . 'When omitted, every installed donor package is considered.',
            )
            ->addOption(
                'target',
                't',
                InputOption::VALUE_REQUIRED,
                'Destination directory for synced skills. Overrides extra.skills.target.',
            )
            ->addOption(
                'trust',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Trust an additional package or vendor for this run only (repeatable).',
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();
        $io = $this->getIO();

        try {
            $project = (new ProjectConfigMapper())->fromExtra($composer->getPackage()->getExtra());
        } catch (MalformedProjectConfig $e) {
            $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }

        try {
            $options = $this->buildOptions($input);
        } catch (\InvalidArgumentException $e) {
            $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return self::INVALID;
        }

        $builtin = $this->loadBuiltinTrustedVendors();

        $donors = $this->discoverDonors($composer, $io, $output);

        $projectRoot = Path::create(\getcwd() ?: '.');
        $plan = (new SyncPlanner())->plan($donors, $project, $options, $builtin, $projectRoot);

        foreach ($plan->skippedUntrustedNames as $name) {
            $io->writeError(\sprintf(
                '<comment>[skip] %s is not in the trusted vendors list. '
                . 'Add it to extra.skills.trusted or rerun with --trust=%s.</comment>',
                $name,
                $name,
            ));
        }

        $approved = $this->resolveUntrustedNamed($plan->approvedDonors, $plan->untrustedNamedDonors, $io, $options);

        $report = (new SyncEngine())->sync($approved, $plan->target);

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
            return self::FAILURE;
        }

        foreach ($report->copied as $skill) {
            $output->writeln(\sprintf(
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

        return self::SUCCESS;
    }

    /**
     * @param array<array-key, mixed> $raw
     * @param non-empty-string        $kind label used in the error message
     *
     * @return list<VendorPattern>
     *
     * @throws \InvalidArgumentException with the original pattern context
     *
     * @psalm-mutation-free
     */
    private static function parsePatterns(array $raw, string $kind): array
    {
        $out = [];
        foreach ($raw as $value) {
            if (!\is_string($value) || $value === '') {
                throw new \InvalidArgumentException(\sprintf('%s must be a non-empty string', $kind));
            }
            try {
                $out[] = VendorPattern::fromString($value);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(\sprintf('%s: %s', $kind, $e->getMessage()), previous: $e);
            }
        }

        return $out;
    }

    /**
     * @throws \InvalidArgumentException when a `packages` or `--trust` pattern is malformed
     */
    private function buildOptions(InputInterface $input): SyncOptions
    {
        $rawPackages = (array) $input->getArgument('packages');
        $rawTrust = (array) $input->getOption('trust');

        /** @var mixed $rawTarget */
        $rawTarget = $input->getOption('target');
        $targetOverride = \is_string($rawTarget) && $rawTarget !== '' ? $rawTarget : null;

        return new SyncOptions(
            packageFilters: self::parsePatterns($rawPackages, 'package argument'),
            extraTrusted: self::parsePatterns($rawTrust, '--trust option'),
            targetOverride: $targetOverride,
            interactive: $input->isInteractive(),
        );
    }

    /**
     * Build the list of donor packages from Composer's local repository. Skips
     * non-donors silently; broken `extra.skills` blocks are surfaced as `-v`
     * warnings and the offending package is dropped.
     *
     * @return list<VendorConfig>
     */
    private function discoverDonors(\Composer\Composer $composer, IOInterface $io, OutputInterface $output): array
    {
        $mapper = new VendorConfigMapper();
        $verbose = $output->isVerbose();
        $donors = [];

        /** @var PackageInterface $package */
        foreach ($composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $extra = $package->getExtra();
            if (!VendorConfigMapper::declaresSkills($extra)) {
                continue;
            }

            $installPath = $composer->getInstallationManager()->getInstallPath($package);
            if ($installPath === null) {
                if ($verbose) {
                    $io->writeError(\sprintf(
                        '<comment>[warn] %s: install path unavailable</comment>',
                        $package->getName(),
                    ));
                }
                continue;
            }

            /** @var non-empty-string $name */
            $name = $package->getName();

            try {
                $donors[] = $mapper->fromExtra($name, Path::create($installPath), $extra);
            } catch (MalformedVendorConfig $e) {
                if ($verbose) {
                    $io->writeError('<comment>[warn] ' . $e->getMessage() . '</comment>');
                }
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
