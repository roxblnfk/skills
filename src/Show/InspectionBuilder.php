<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\TrustedVendors;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Discovery\DiscoveryResolver;
use LLM\Skills\Discovery\InstalledSkill;
use LLM\Skills\Discovery\InstalledSkillScanner;
use LLM\Skills\Discovery\Provider\DonorProvider;
use LLM\Skills\Discovery\Skill;
use LLM\Skills\Discovery\SkillEnumerator;
use LLM\Skills\Discovery\SkillFrontmatterReader;
use LLM\Skills\Sync\SkillConflict;
use LLM\Skills\Sync\SyncEngine;
use LLM\Skills\Sync\SyncPlan;
use LLM\Skills\Sync\SyncPlanner;

/**
 * Composes the discovery pipeline into an {@see InspectionReport}.
 *
 * Reuses every read-only service that powers `skills:update`:
 * {@see DonorDiscovery}, {@see SyncPlanner}, {@see SkillEnumerator},
 * {@see InstalledSkillScanner}, {@see SyncEngine} (in dry-run mode for
 * conflict detection), plus the two services specific to `show`:
 * {@see TrustAttributor} and {@see DriftDetector}.
 *
 * The builder is pure with respect to user-visible state: it reads
 * Composer's local repository, the donor packages' source directories,
 * and the project's target directory. It writes nothing. Whatever
 * diagnostics need to reach the user (e.g. `install path unavailable`
 * warnings) are folded into the report's `skipped` section so the
 * formatter can surface them uniformly — there is no separate IO
 * channel for `show`.
 */
final readonly class InspectionBuilder
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private SyncPlanner $planner = new SyncPlanner(),
        private SkillEnumerator $skillEnumerator = new SkillEnumerator(),
        private InstalledSkillScanner $installedScanner = new InstalledSkillScanner(),
        private SyncEngine $engine = new SyncEngine(),
        private DriftDetector $drift = new DriftDetector(),
        private SkillFrontmatterReader $frontmatter = new SkillFrontmatterReader(),
        private DiscoveryResolver $discoveryResolver = new DiscoveryResolver(),
    ) {}

    /**
     * @param Path $projectRoot consumer project root, supplied by the caller
     * @param DonorProvider $provider source of donors (today
     *        {@see \LLM\Skills\Discovery\Provider\ComposerProvider}); future
     *        GitHub / npm / skills.sh providers plug in here
     * @param ProjectConfig $project pre-resolved project config. The caller
     *        ({@see \LLM\Skills\Show\ShowRunner}) loads it via
     *        {@see \LLM\Skills\Config\Mapper\ProjectConfigMapper::forProject()}
     *        so it can surface the `skills.json` shadowing warning through IO;
     *        the builder itself stays pure (no IO).
     *
     * @throws MalformedProjectConfig when planner-level path validation fails.
     */
    public function build(
        Path $projectRoot,
        DonorProvider $provider,
        ProjectConfig $project,
        SyncOptions $options,
    ): InspectionReport {
        $builtin = $this->loadBuiltinTrustedVendors();

        $discovery = $provider->discover($projectRoot);

        $discoveryActive = $options->discovery ?? $project->discovery;
        $resolution = $this->discoveryResolver->resolve(
            $discovery->discoverable,
            $discoveryActive,
            $options,
        );
        $donors = [...$discovery->donors, ...$resolution->included];

        $directDeps = $provider->directDependencies($projectRoot);
        $plan = $this->planner->plan(
            $donors,
            $project,
            $options,
            $builtin,
            $projectRoot,
            $directDeps,
        );

        $attributor = $this->buildAttributor($project, $options, $builtin, $directDeps);

        // Conflict detection: run the engine in dry-run over the approved
        // donors' skills. We get a SkillConflict[] without touching the
        // filesystem.
        $approvedEnumeration = $this->skillEnumerator->enumerate($plan->approvedDonors);
        $approvedSkills = $approvedEnumeration->skills;
        $conflictReport = $this->engine->sync($approvedSkills, $plan->target, dryRun: true);
        $conflictMap = $this->indexConflicts($conflictReport->conflicts);

        $installed = $this->installedScanner->scan($plan->target);
        $installedByName = $this->indexInstalledByName($installed);

        $donorInspections = $this->buildDonorInspections(
            $plan->approvedDonors,
            $approvedSkills,
            $attributor,
            $conflictMap,
            $installedByName,
        );

        $skipped = $this->buildSkippedList(
            $discovery->malformed,
            $approvedEnumeration->warnings,
            $plan,
            $resolution->excluded,
        );

        return new InspectionReport(
            target: $plan->target,
            donors: $donorInspections,
            skipped: $skipped,
            discoveryActive: $discoveryActive,
            undeclaredCandidatesCount: \count($resolution->excluded),
            aliases: $this->inspectAliases($plan),
        );
    }

    /**
     * @psalm-pure
     */
    private static function pathsEqual(string $a, string $b): bool
    {
        $aNorm = \rtrim(\str_replace('/', \DIRECTORY_SEPARATOR, $a), \DIRECTORY_SEPARATOR);
        $bNorm = \rtrim(\str_replace('/', \DIRECTORY_SEPARATOR, $b), \DIRECTORY_SEPARATOR);

        return \DIRECTORY_SEPARATOR === '\\'
            ? \strcasecmp($aNorm, $bNorm) === 0
            : $aNorm === $bNorm;
    }

    /**
     * For each configured alias, decide whether it currently points at
     * the target on disk. The check is intentionally observational —
     * it never creates, removes, or touches anything. A non-existent
     * alias is **not** drift (the next sync will create it); only an
     * existing junction/symlink/directory pointing somewhere else is.
     *
     * @return list<AliasInspection>
     */
    private function inspectAliases(SyncPlan $plan): array
    {
        if ($plan->aliases === []) {
            return [];
        }

        $resolvedTarget = \realpath((string) $plan->target);

        $out = [];
        foreach ($plan->aliases as $alias) {
            $out[] = new AliasInspection($alias, $this->detectAliasDrift($alias, $resolvedTarget));
        }

        return $out;
    }

    /**
     * Return the resolved path the alias currently points at, but only
     * when that resolution disagrees with the target. `null` means
     * "nothing to report" — either the alias is correct, doesn't exist
     * yet, or can't be resolved.
     *
     * @param string|false $resolvedTarget result of {@see \realpath()} on the configured target
     *
     * @return non-empty-string|null
     */
    private function detectAliasDrift(\Internal\Path $alias, string|false $resolvedTarget): ?string
    {
        $aliasStr = (string) $alias;
        if (!\file_exists($aliasStr) && !\is_link($aliasStr)) {
            return null;
        }

        $resolvedAlias = \realpath($aliasStr);
        if ($resolvedAlias === false || $resolvedAlias === '') {
            return null;
        }

        if ($resolvedTarget !== false && $resolvedTarget !== '' && self::pathsEqual($resolvedAlias, $resolvedTarget)) {
            return null;
        }

        return $resolvedAlias;
    }

    /**
     * @param list<non-empty-string> $directDeps
     *
     * @psalm-pure
     */
    private function buildAttributor(
        ProjectConfig $project,
        SyncOptions $options,
        TrustedVendors $builtin,
        array $directDeps,
    ): TrustAttributor {
        return new TrustAttributor(
            builtin: $project->trustedReplace ? null : $builtin,
            project: $project->trusted,
            cli: new TrustedVendors($options->extraTrusted),
            // When `trustedReplace: true` the planner ignores direct
            // deps too — mirror that here so the formatter never
            // annotates a donor with "direct dep" trust that did not
            // actually contribute to the approval decision.
            directDeps: $project->trustedReplace ? null : $directDeps,
        );
    }

    /**
     * @param list<SkillConflict> $conflicts
     *
     * @return array<non-empty-string, list<non-empty-string>> map of skill-name → packages
     *
     * @psalm-pure
     */
    private function indexConflicts(array $conflicts): array
    {
        $out = [];
        foreach ($conflicts as $conflict) {
            $out[$conflict->name] = $conflict->packages;
        }

        return $out;
    }

    /**
     * @param list<InstalledSkill> $installed
     *
     * @return array<non-empty-string, InstalledSkill>
     *
     * @psalm-pure
     */
    private function indexInstalledByName(array $installed): array
    {
        $out = [];
        foreach ($installed as $skill) {
            $out[$skill->name] = $skill;
        }

        return $out;
    }

    /**
     * @param list<VendorConfig> $approvedDonors
     * @param list<Skill> $approvedSkills
     * @param array<non-empty-string, list<non-empty-string>> $conflictMap
     * @param array<non-empty-string, InstalledSkill> $installedByName
     *
     * @return list<DonorInspection>
     */
    private function buildDonorInspections(
        array $approvedDonors,
        array $approvedSkills,
        TrustAttributor $attributor,
        array $conflictMap,
        array $installedByName,
    ): array {
        // Group the flat skills list by donor name for fast lookup.
        $skillsByDonor = [];
        foreach ($approvedSkills as $skill) {
            $skillsByDonor[$skill->packageName][] = $skill;
        }

        $result = [];
        foreach ($approvedDonors as $donor) {
            $donorSkills = $skillsByDonor[$donor->packageName] ?? [];
            $skillInspections = [];
            foreach ($donorSkills as $skill) {
                $skillInspections[] = new SkillInspection(
                    skill: $skill,
                    status: $this->resolveStatus($skill, $installedByName),
                    conflictWith: $this->resolveConflictPartner($skill, $conflictMap),
                    description: $this->resolveDescription($skill),
                );
            }

            $result[] = new DonorInspection(
                donor: $donor,
                trustSource: $attributor->attribute($donor->packageName),
                skills: $skillInspections,
            );
        }

        return $result;
    }

    /**
     * @param array<non-empty-string, InstalledSkill> $installedByName
     */
    private function resolveStatus(Skill $skill, array $installedByName): SyncStatus
    {
        $installed = $installedByName[$skill->name] ?? null;
        if ($installed === null) {
            return SyncStatus::NotSynced;
        }

        return $this->drift->differs($skill->sourceDir, $installed->dir)
            ? SyncStatus::Drift
            : SyncStatus::InSync;
    }

    /**
     * Read the `description:` field from the donor's `SKILL.md`
     * frontmatter. Returns `null` for skills whose file is missing,
     * malformed, or has no `description` key — the formatter renders
     * those rows with an empty second column.
     */
    private function resolveDescription(Skill $skill): ?string
    {
        $fm = $this->frontmatter->read($skill->sourceDir);
        if ($fm === null) {
            return null;
        }
        $value = $fm['description'] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * @param array<non-empty-string, list<non-empty-string>> $conflictMap
     *
     * @return non-empty-string|null
     *
     * @psalm-pure
     */
    private function resolveConflictPartner(Skill $skill, array $conflictMap): ?string
    {
        $packages = $conflictMap[$skill->name] ?? null;
        if ($packages === null) {
            return null;
        }
        // The conflict list includes the current skill's own package; pick
        // any other entry as the partner. For a 2-way conflict that's the
        // single other contender; for an n-way conflict we just name one
        // representative — the formatter can decide to expand if desired.
        foreach ($packages as $candidate) {
            if ($candidate !== $skill->packageName) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<\LLM\Skills\Discovery\MalformedDonor> $malformed
     * @param list<string> $enumerationWarnings raw text from {@see SkillEnumerator}
     * @param list<VendorConfig> $unactivatedDiscoverable packages that ship a `skills/` root but
     *         were not promoted into the run (because `--discovery` is off); listed under
     *         `not-declared` so the user sees their names alongside the actionable hint
     *
     * @return list<SkippedDonor>
     *
     * @psalm-mutation-free
     */
    private function buildSkippedList(
        array $malformed,
        array $enumerationWarnings,
        \LLM\Skills\Sync\SyncPlan $plan,
        array $unactivatedDiscoverable,
    ): array {
        $result = [];

        foreach ($malformed as $bad) {
            $result[] = new SkippedDonor(
                packageName: $bad->packageName,
                reason: SkipReason::Malformed,
                detail: $bad->reason,
            );
        }

        foreach ($unactivatedDiscoverable as $donor) {
            $result[] = new SkippedDonor(
                packageName: $donor->packageName,
                reason: SkipReason::NotDeclared,
            );
        }

        foreach ($plan->skippedUntrustedNames as $name) {
            $result[] = new SkippedDonor(
                packageName: $name,
                reason: SkipReason::Untrusted,
            );
        }

        foreach ($plan->filteredOutDonors as $donor) {
            $result[] = new SkippedDonor(
                packageName: $donor->packageName,
                reason: SkipReason::FilteredOut,
            );
        }

        // Enumeration warnings are "source dir does not exist / is unreadable" —
        // parse the package name back out so we can match it to a typed reason.
        foreach ($enumerationWarnings as $warning) {
            $name = $this->extractPackageName($warning);
            if ($name === null) {
                continue;
            }
            $result[] = new SkippedDonor(
                packageName: $name,
                reason: SkipReason::SourceMissing,
                detail: $warning,
            );
        }

        return $result;
    }

    /**
     * Enumeration warnings are of the form `acme/foo: source directory "src" does not exist`.
     * Extract the leading package name.
     *
     * @return non-empty-string|null
     *
     * @psalm-pure
     */
    private function extractPackageName(string $warning): ?string
    {
        $colonAt = \strpos($warning, ':');
        if ($colonAt === false || $colonAt === 0) {
            return null;
        }
        $name = \substr($warning, 0, $colonAt);
        if ($name === '') {
            return null;
        }

        return $name;
    }

    /**
     * @psalm-suppress MissingPureAnnotation,ImpureFunctionCall,ImpureMethodCall reading a file
     *         shipped with the package is conceptually pure but psalm cannot prove it.
     *
     * @psalm-pure
     */
    private function loadBuiltinTrustedVendors(): TrustedVendors
    {
        return (new \LLM\Skills\Config\TrustedVendorRegistry())->loadForProvider(
            \LLM\Skills\Discovery\Provider\ProviderId::COMPOSER,
        );
    }
}
