<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use Composer\IO\IOInterface;
use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\Mapper\MigrationStatus;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Config\Mapper\ProjectConfigMigrator;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\TrustedVendorRegistry;
use LLM\Skills\Config\TrustedVendors;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Discovery\AutoDiscoveryProbe;
use LLM\Skills\Discovery\DiscoveryResolver;
use LLM\Skills\Discovery\Provider\DonorProvider;
use LLM\Skills\Discovery\Provider\ProviderId;
use LLM\Skills\Discovery\Skill;
use LLM\Skills\Discovery\SkillEnumerator;
use Symfony\Component\Console\Command\Command;

/**
 * Shared body of `skills:update` — independent of which entrypoint invoked it.
 *
 * Two entrypoints share this runner:
 *
 * - {@see \LLM\Skills\Composer\Command\Sync} — wired into Composer via the
 *   plugin's {@see \LLM\Skills\Composer\CommandProvider}; the Composer
 *   instance flows into the {@see DonorProvider} (today
 *   {@see \LLM\Skills\Discovery\Provider\ComposerProvider}).
 * - {@see \LLM\Skills\Console\Command\Sync} — the PHAR/binary entrypoint
 *   shipped as `bin/skills`; the Composer instance is bootstrapped
 *   manually via {@see \Composer\Factory::create()}. When that fails
 *   (e.g. no `composer.json` at cwd) the provider becomes inactive
 *   and the runner emits the
 *   `[llm/skills] no donor providers are active — nothing to sync.`
 *   notice and exits 0.
 *
 * Whatever the source, the runner orchestrates the pipeline:
 *
 *   1. Map project config → {@see \LLM\Skills\Config\ProjectConfig}
 *      via `skills.json` (when present) or inline `extra.skills`.
 *   2. {@see DonorProvider::discover()}: enumerate donor packages.
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
        private SkillEnumerator $skillEnumerator = new SkillEnumerator(),
        private ProjectConfigMapper $projectMapper = new ProjectConfigMapper(),
        private DiscoveryResolver $discoveryResolver = new DiscoveryResolver(),
        private SymlinkLinker $symlinkLinker = new SymlinkLinker(),
        private ProjectConfigMigrator $migrator = new ProjectConfigMigrator(),
    ) {}

    /**
     * @param Path $projectRoot consumer project root, typically the entrypoint's cwd
     * @param DonorProvider $provider source of donor packages (Composer today; in future,
     *        also GitHub / npm / skills.sh — see {@see DonorProvider})
     * @param mixed $extra raw `composer.json` `extra` field, or `null` when no
     *        `composer.json` is around (standalone-mode bin run). Drives the legacy
     *        inline `extra.skills` fallback when `skills.json` is absent.
     */
    public function run(
        Path $projectRoot,
        DonorProvider $provider,
        mixed $extra,
        IOInterface $io,
        SyncOptions $options,
    ): int {
        // Auto-migration is part of the write-mode contract: any
        // `skills:update` (or `post-update-cmd` auto-sync invocation)
        // moves a legacy inline extra.skills block into the canonical
        // skills.json before doing anything else. Skipped silently
        // when there is no migration to do (skills.json exists, or no
        // inline keys, or no composer.json at all). The
        // `$options->autoMigrate=false` opt-out is used by the
        // `post-install-cmd` hook, which must not rewrite
        // `composer.json` mid-install.
        if ($options->autoMigrate) {
            $migration = $this->migrator->migrate($projectRoot, $io);
            if ($migration->status === MigrationStatus::Failed) {
                return Command::FAILURE;
            }
            if ($migration->status === MigrationStatus::Migrated) {
                $io->write(\sprintf(
                    '<info>[migrate]</info> moved extra.skills → skills.json (%s)',
                    \implode(', ', $migration->migratedKeys),
                ));
                // The fresh skills.json is now the source of truth;
                // the stale in-memory $extra (still containing the
                // migrated keys) must be discarded so forProject()
                // reads from disk.
                $extra = null;
            }
        }

        try {
            $configResolution = $this->projectMapper->forProject($projectRoot, $extra);
        } catch (MalformedProjectConfig $e) {
            $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $project = $configResolution->config;
        $this->emitShadowedKeysWarning($io, $configResolution->ignoredInlineKeys);

        // No donor provider can contribute. Reasons vary by provider —
        // {@see \LLM\Skills\Discovery\Provider\ComposerProvider} is
        // inactive when no Composer instance was supplied (no composer.json
        // at cwd, or `Factory::create()` threw). The entrypoint emits a
        // `-v` line naming the actual cause; this user-facing notice
        // stays neutral so it stays accurate across all providers
        // (today only Composer, eventually also GitHub / npm / skills.sh).
        if (!$provider->isActive($projectRoot)) {
            $io->write(
                '<comment>[llm/skills] no donor providers are active — nothing to sync. '
                . 'Run with -v for details.</comment>',
            );
            return Command::SUCCESS;
        }

        $builtin = $this->loadBuiltinTrustedVendors();

        $discovery = $provider->discover($projectRoot);
        $this->emitWarnings($io, $discovery->warnings);

        $discoveryActive = $options->discovery ?? $project->discovery;
        $discoveryResolution = $this->discoveryResolver->resolve(
            $discovery->discoverable,
            $discoveryActive,
            $options,
        );
        $donors = [...$discovery->donors, ...$discoveryResolution->included];

        // `--from=<id>` narrows the sync to a single
        // provider's donors. Provenance is tagged at the source
        // (`composer` for ComposerProvider, the entry's `from` for
        // RemoteProvider) so a simple equality filter is enough.
        if ($options->fromFilter !== null) {
            $filter = $options->fromFilter;
            $donors = \array_values(\array_filter(
                $donors,
                static fn(VendorConfig $d): bool => $d->provenance === $filter,
            ));
            if ($donors === []) {
                $io->writeError(\sprintf(
                    '<comment>[llm/skills] --from=%s matched no donors</comment>',
                    $filter,
                ));
            }
        }

        $directDeps = $provider->directDependencies($projectRoot);
        try {
            $plan = $this->planner->plan(
                $donors,
                $project,
                $options,
                $builtin,
                $projectRoot,
                $directDeps,
            );
        } catch (MalformedProjectConfig $e) {
            // Containment-check failures (target / alias escapes the
            // project root) surface here. Same UX as inline / skills.json
            // shape errors caught by the mapper above.
            $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

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
            $this->emitTrailingDiagnostics($io, $plan, $discoveryResolution->excluded);
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

        $aliasFailed = $this->processAliases($io, $plan, $options->dryRun);

        $this->emitTrailingDiagnostics($io, $plan, $discoveryResolution->excluded);

        // Alias errors fail the run loudly — silent partial success would
        // mask broken `.claude/skills` / `.cursor/skills` aliases on CI.
        return $aliasFailed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Process every alias declared in the plan through {@see SymlinkLinker}.
     * Returns `true` if any alias errored — the caller turns that into a
     * non-zero exit code.
     */
    private function processAliases(IOInterface $io, SyncPlan $plan, bool $dryRun): bool
    {
        if ($plan->aliases === []) {
            return false;
        }

        $anyFailed = false;
        foreach ($plan->aliases as $alias) {
            $result = $this->symlinkLinker->link($alias, $plan->target, $dryRun);
            $this->emitAliasOutcome($io, $result);
            if ($result->isFailure()) {
                $anyFailed = true;
            }
        }

        return $anyFailed;
    }

    private function emitAliasOutcome(IOInterface $io, LinkResult $result): void
    {
        $aliasStr = (string) $result->alias;
        $targetStr = (string) $result->target;

        switch ($result->status) {
            case LinkStatus::Created:
                $io->write(\sprintf('<info>[link]</info> %s → %s', $aliasStr, $targetStr));
                return;
            case LinkStatus::AlreadyCorrect:
                $io->write(\sprintf('<info>[link]</info> %s → %s (already correct)', $aliasStr, $targetStr));
                return;
            case LinkStatus::WouldCreate:
                $io->write(\sprintf('<info>[would link]</info> %s → %s', $aliasStr, $targetStr));
                return;
            case LinkStatus::Failed:
                $io->writeError(\sprintf(
                    '<error>[link-failed] %s → %s: %s</error>',
                    $aliasStr,
                    $targetStr,
                    $result->reason ?? 'unknown error',
                ));
                return;
        }
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
     * `skills.json` won the precedence contest but `composer.json` still
     * carries project-level keys under `extra.skills`. Surface their
     * names under `-v` so a confused user can see why their inline
     * `target` (or any other key) had no effect.
     *
     * @param list<non-empty-string> $ignored
     */
    private function emitShadowedKeysWarning(IOInterface $io, array $ignored): void
    {
        if ($ignored === []) {
            return;
        }

        $io->writeError(
            '<comment>[warn] skills.json present; the following extra.skills keys in '
            . 'composer.json are ignored: ' . \implode(', ', $ignored) . '</comment>',
            verbosity: IOInterface::VERBOSE,
        );
    }

    /**
     * @psalm-suppress MissingPureAnnotation,ImpureFunctionCall,ImpureMethodCall reading a file
     *         shipped with the package is conceptually pure but psalm cannot prove it.
     *
     * @psalm-pure
     */
    private function loadBuiltinTrustedVendors(): TrustedVendors
    {
        return (new TrustedVendorRegistry())->loadForProvider(ProviderId::COMPOSER);
    }
}
