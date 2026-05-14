<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use Composer\Composer;
use Composer\IO\IOInterface;
use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\TrustedVendors;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Discovery\AutoDiscoveryProbe;
use LLM\Skills\Discovery\DiscoveryResolver;
use LLM\Skills\Discovery\DonorDiscovery;
use LLM\Skills\Discovery\Skill;
use LLM\Skills\Discovery\SkillEnumerator;
use LLM\Skills\Info;
use Symfony\Component\Console\Command\Command;

/**
 * Shared body of `skills:update` — independent of which entrypoint invoked it.
 *
 * Two entrypoints share this runner:
 *
 * - {@see \LLM\Skills\Composer\Command\Sync} — wired into Composer via the
 *   plugin's {@see \LLM\Skills\Composer\CommandProvider}; the Composer
 *   instance is provided by `BaseCommand::requireComposer()`.
 * - {@see \LLM\Skills\Console\Command\Sync} — the PHAR/binary entrypoint
 *   shipped as `bin/skills`; the Composer instance is bootstrapped manually
 *   via {@see \Composer\Factory::create()}.
 *
 * Whatever the source, the runner orchestrates the pipeline:
 *
 *   1. Map root `extra.skills` → {@see \LLM\Skills\Config\ProjectConfig}.
 *   2. {@see DonorDiscovery}: Composer → list of donor {@see VendorConfig}.
 *   3. {@see DiscoveryResolver}: merge auto-discovered donors that the user
 *      asked for (via `--discovery` or a positional name) into the donor list.
 *   4. {@see SyncPlanner}: trust + filter partitioning → {@see SyncPlan}.
 *   5. {@see SkillEnumerator}: enumerate skill subdirs for approved donors.
 *   6. {@see SyncEngine}: detect conflicts, write files.
 *   7. Format the {@see SyncReport} grouped by package and emit the trailing
 *      `[skip]` / `[hint]` diagnostics.
 *
 * Returns {@see Command::SUCCESS} / {@see Command::FAILURE} /
 * {@see Command::INVALID} so both entrypoints can return it as-is.
 */
final readonly class SyncRunner
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private SyncPlanner $planner = new SyncPlanner(),
        private SyncEngine $engine = new SyncEngine(),
        private DonorDiscovery $donorDiscovery = new DonorDiscovery(),
        private SkillEnumerator $skillEnumerator = new SkillEnumerator(),
        private ProjectConfigMapper $projectMapper = new ProjectConfigMapper(),
        private DiscoveryResolver $discoveryResolver = new DiscoveryResolver(),
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

        $discovery = $this->donorDiscovery->discover($composer);
        $this->emitWarnings($io, $discovery->warnings);

        $discoveryActive = $options->discovery ?? $project->discovery;
        $resolution = $this->discoveryResolver->resolve(
            $discovery->discoverable,
            $discoveryActive,
            $options,
        );
        $donors = [...$discovery->donors, ...$resolution->included];

        $projectRoot = Path::create(\getcwd() ?: '.');
        $plan = $this->planner->plan($donors, $project, $options, $builtin, $projectRoot);

        if ($options->hasPackageFilters() && $plan->approvedDonors === []) {
            $patterns = \implode(
                ', ',
                \array_map(static fn($p): string => $p->raw, $options->packageFilters),
            );
            $io->writeError(\sprintf(
                '<error>[llm/skills] no installed donor package matches: %s</error>',
                $patterns,
            ));
            return Command::INVALID;
        }

        if ($options->dryRun) {
            $io->write('<comment>[dry-run] no files will be written</comment>');
        }

        $enumeration = $this->skillEnumerator->enumerate($plan->approvedDonors);
        $this->emitWarnings($io, $enumeration->warnings);

        $report = $this->engine->sync($enumeration->skills, $plan->target, dryRun: $options->dryRun);

        if ($report->hasConflicts()) {
            foreach ($report->conflicts as $conflict) {
                $io->writeError(\sprintf(
                    '<error>[conflict] skill "%s" declared by: %s</error>',
                    $conflict->name,
                    \implode(', ', $conflict->packages),
                ));
            }
            $io->writeError('<error>Sync aborted due to skill-name conflicts; nothing was written.</error>');
            $this->emitTrailingDiagnostics($io, $plan, $resolution->excluded);
            return Command::FAILURE;
        }

        $this->emitCopyReport($io, $report->copied, $options->dryRun);

        $verb = $options->dryRun ? 'would sync' : 'synced';
        $io->write(\sprintf(
            '<info>[llm/skills] %s %d skill(s) into %s</info>',
            $verb,
            \count($report->copied),
            (string) $plan->target,
        ));

        $this->emitTrailingDiagnostics($io, $plan, $resolution->excluded);

        return Command::SUCCESS;
    }

    /**
     * Group copied skills by donor package so a multi-package sync reads as
     * a list of vendor sections, not a flat stream of `name ← package` rows
     * that the eye has to re-sort.
     *
     * @param list<Skill> $copied
     */
    private function emitCopyReport(IOInterface $io, array $copied, bool $dryRun): void
    {
        if ($copied === []) {
            return;
        }

        $action = $dryRun ? '[would copy]' : '[copy]';
        $byPackage = [];
        foreach ($copied as $skill) {
            $byPackage[$skill->packageName][] = $skill->name;
        }

        foreach ($byPackage as $package => $names) {
            $io->write('<fg=cyan>' . $package . '</>');
            foreach ($names as $name) {
                $io->write('  <info>' . $action . '</info> ' . $name);
            }
        }
    }

    /**
     * Emit the "what didn't make it" footer: untrusted donors that were
     * silently dropped, plus the `--discovery` hint when relevant. Lives
     * at the bottom of the output (after the per-package copy report and
     * the summary line) so the reader sees results first and side
     * notices after.
     */
    /**
     * @param list<VendorConfig> $undeclaredCandidates packages still left out of this run (i.e.
     *         {@see DiscoveryResolution::$excluded}); drives the discovery hint
     */
    private function emitTrailingDiagnostics(
        IOInterface $io,
        SyncPlan $plan,
        array $undeclaredCandidates,
    ): void {
        if ($plan->skippedUntrustedNames !== []) {
            $io->writeError(\sprintf(
                '<comment>[skip] %d untrusted package(s) were not synced:</comment>',
                \count($plan->skippedUntrustedNames),
            ));
            foreach ($plan->skippedUntrustedNames as $name) {
                $io->writeError('<comment>  - ' . $name . '</comment>');
            }
            $io->writeError(
                '<comment>       Add them to extra.skills.trusted or rerun with '
                . '--trust=<pattern> (e.g. --trust=acme/skills-pro, --trust=acme/*, --trust=*; '
                . 'repeatable).</comment>',
            );
        }

        if ($undeclaredCandidates !== []) {
            $io->write(\sprintf(
                '<comment>[hint] %d package(s) ship undeclared skills under %s/. '
                . 'Rerun with --discovery (-d) to include them, or set extra.skills.discovery: true.</comment>',
                \count($undeclaredCandidates),
                AutoDiscoveryProbe::SOURCE_DIR,
            ));
        }
    }

    /**
     * @param list<string> $warnings
     */
    private function emitWarnings(IOInterface $io, array $warnings): void
    {
        foreach ($warnings as $warning) {
            $io->writeError('<comment>[warn] ' . $warning . '</comment>', verbosity: IOInterface::VERBOSE);
        }
    }

    /**
     * @psalm-suppress MissingPureAnnotation,ImpureFunctionCall reading a file shipped with the
     *         package is conceptually pure but psalm cannot prove it.
     *
     * @psalm-pure
     */
    private function loadBuiltinTrustedVendors(): TrustedVendors
    {
        $path = Info::ROOT_DIR . '/resources/trusted-vendors.txt';
        $content = \file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Failed to read built-in trusted-vendors list at ' . $path);
        }

        $patterns = [];
        foreach (\explode("\n", $content) as $line) {
            $line = \trim($line);
            if ($line === '' || \str_starts_with($line, '#')) {
                continue;
            }
            $patterns[] = $line;
        }

        return TrustedVendors::fromStrings(...$patterns);
    }
}
