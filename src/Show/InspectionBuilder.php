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
use LLM\Skills\Filesystem\LinkGuard;
use LLM\Skills\Filesystem\PathContainment;
use LLM\Skills\Sync\LinkStatus;
use LLM\Skills\Sync\SkillConflict;
use LLM\Skills\Sync\SymlinkLinker;
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
        private SymlinkLinker $linker = new SymlinkLinker(),
    ) {}

    /**
     * @param Path $projectRoot consumer project root, supplied by the caller
     * @param DonorProvider $provider source of donors (today
     *        {@see \LLM\Skills\Discovery\Provider\ComposerProvider}); future
     *        GitHub / npm / skills.sh providers plug in here
     * @param ProjectConfig $project pre-resolved project config. The caller
     *        ({@see ShowRunner}) loads it via
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

        $discovery = $provider->discover($projectRoot, $this->planner->target($project, $options, $projectRoot));

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
        $approvedEnumeration = $this->skillEnumerator->enumerate($plan->approvedDonors, $plan->target);
        $approvedSkills = $approvedEnumeration->skills;
        // A scoped report checks the donors it leaves out too, as the run does.
        $bystanders = $options->isScoped()
            ? $this->skillEnumerator->enumerate(
                $this->planner->bystanders($plan, $donors, $project, $options, $builtin, $projectRoot, $directDeps),
                $plan->target,
            )->skills
            : [];
        $conflictReport = $this->engine->sync($approvedSkills, $plan->target, dryRun: true, bystanders: $bystanders);
        $conflictMap = $this->indexConflicts($conflictReport->conflicts);

        // An unreadable target is reported as nothing installed: `show` only
        // marks skills `[✓]` / `[ ]`, so the worst it costs is an optimistic
        // "would be written" row, not a destructive decision.
        $installed = $this->installedScanner->scan($plan->target) ?? [];
        $installedByKey = $this->indexInstalledByKey($installed);

        // A skill the sync leaves out, because its source is the target or its
        // directory in the target is a link it never writes through, is listed
        // as skipped rather than as a skill waiting to be written or drifted.
        // A conflict aborts the sync before either is looked at, so a
        // conflicting skill stays listed to carry its conflict marker.
        $leftOut = [];
        $listedSkills = [];
        foreach ($approvedSkills as $skill) {
            if (isset($conflictMap[SkillConflict::collisionKey($skill->name)])) {
                $listedSkills[] = $skill;
                continue;
            }
            $destination = $plan->target->join($skill->name);
            if (PathContainment::isSame($plan->target, $skill->sourceDir)) {
                $leftOut[] = new SkippedDonor(
                    packageName: $skill->packageName,
                    reason: SkipReason::TargetIsSource,
                    detail: \sprintf(
                        'skill "%s": its source is the target directory itself, so there is nowhere to copy it',
                        $skill->name,
                    ),
                );
            } elseif (!PathContainment::isSame($destination, $skill->sourceDir)
                && LinkGuard::isLink((string) $destination)
            ) {
                $leftOut[] = new SkippedDonor(
                    packageName: $skill->packageName,
                    reason: SkipReason::TargetIsLink,
                    detail: \sprintf(
                        'skill "%s": its directory in the target is a symlink/junction, which the sync never writes through',
                        $skill->name,
                    ),
                );
            } else {
                $listedSkills[] = $skill;
            }
        }

        $donorInspections = $this->buildDonorInspections(
            $plan->approvedDonors,
            $listedSkills,
            $attributor,
            $conflictMap,
            $installedByKey,
        );

        $skipped = $this->buildSkippedList(
            $discovery->malformed,
            $discovery->failures,
            $approvedEnumeration->warnings,
            $approvedEnumeration->droppedDonors,
            $approvedEnumeration->skipped,
            $plan,
            $resolution->excluded,
        );
        foreach ($approvedEnumeration->internal as $skill) {
            // Named as an allowlist names it, which is what installs it anyway.
            $label = $skill->canonicalName === $skill->name
                ? \sprintf('skill "%s"', $skill->name)
                : \sprintf('skill "%s" (directory "%s")', $skill->canonicalName, $skill->name);
            // Nothing records who wrote a directory in the target, so one under
            // the skill's name is reported without claiming it as this copy.
            $inTarget = $this->findInstalled(
                $skill,
                $installedByKey[SkillConflict::collisionKey($skill->name)] ?? [],
            ) !== null;
            $skipped[] = new SkippedDonor(
                packageName: $skill->packageName,
                reason: SkipReason::Internal,
                detail: $inTarget
                    ? $label . ': marked internal, not synced; the target\'s directory of its name stays'
                        . ' until an unscoped clean sync'
                    : $label . ': marked internal, not installed',
            );
        }
        \array_push($skipped, ...$leftOut);

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
     * alias is **not** drift (the next sync will create it). A link the
     * next sync re-points is flagged as a relink, found by a dry run of
     * {@see SymlinkLinker}; anything else resolving somewhere other than
     * the target, such as a real directory, is drift.
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
            $link = $this->linker->link($alias, $plan->target, dryRun: true);
            $out[] = $link->status === LinkStatus::WouldReplace
                ? new AliasInspection($alias, relinkFrom: $link->previous)
                : new AliasInspection($alias, $this->detectAliasDrift($alias, $resolvedTarget));
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
    private function detectAliasDrift(Path $alias, string|false $resolvedTarget): ?string
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
     * @return array<string, list<non-empty-string>> map of {@see SkillConflict::collisionKey()} → packages
     *
     * @psalm-pure
     */
    private function indexConflicts(array $conflicts): array
    {
        $out = [];
        foreach ($conflicts as $conflict) {
            $out[SkillConflict::collisionKey($conflict->name)] = $conflict->packages;
        }

        return $out;
    }

    /**
     * @param list<InstalledSkill> $installed
     *
     * @return array<string, list<InstalledSkill>> keyed by {@see SkillConflict::collisionKey()}
     *
     * @psalm-pure
     */
    private function indexInstalledByKey(array $installed): array
    {
        $out = [];
        foreach ($installed as $skill) {
            $out[SkillConflict::collisionKey($skill->name)][] = $skill;
        }

        return $out;
    }

    /**
     * @param list<VendorConfig> $approvedDonors
     * @param list<Skill> $approvedSkills
     * @param array<string, list<non-empty-string>> $conflictMap
     * @param array<string, list<InstalledSkill>> $installedByKey
     *
     * @return list<DonorInspection>
     */
    private function buildDonorInspections(
        array $approvedDonors,
        array $approvedSkills,
        TrustAttributor $attributor,
        array $conflictMap,
        array $installedByKey,
    ): array {
        $result = [];
        foreach ($approvedDonors as $donor) {
            // A single package can yield several discovered donor rows — one
            // per container (`.claude/skills`, `skills/<category>`, …). Assign
            // each skill to the row it actually came from so the same skill is
            // not listed under every row of its package.
            $donorSkills = \array_values(\array_filter(
                $approvedSkills,
                fn(Skill $skill): bool => $this->skillBelongsToDonor($skill, $donor),
            ));
            $skillInspections = [];
            foreach ($donorSkills as $skill) {
                $skillInspections[] = new SkillInspection(
                    skill: $skill,
                    status: $this->resolveStatus($skill, $installedByKey),
                    conflictWith: $this->resolveConflictPartner($skill, $conflictMap),
                    description: $this->resolveDescription($skill),
                );
            }

            // A user-declared donor (`sources[]` entry) is trusted by the
            // declaration itself — the planner never consults the trust
            // lists for it, so the attributor must not credit one either
            // (the name could coincidentally match, e.g. built-in).
            $result[] = new DonorInspection(
                donor: $donor,
                trustSource: $donor->implicitTrust
                    ? TrustSource::Declared
                    : $attributor->attribute($donor->packageName),
                skills: $skillInspections,
            );
        }

        return $result;
    }

    /**
     * Decide whether `$skill` was produced by `$donor`. Declared donors are
     * one-per-package, so the package name suffices. Auto-discovered donors may
     * be several per package (one per container); a skill belongs to the row
     * whose explicit directory list contains its source directory.
     *
     * @psalm-pure
     */
    private function skillBelongsToDonor(Skill $skill, VendorConfig $donor): bool
    {
        if ($skill->packageName !== $donor->packageName) {
            return false;
        }

        if ($donor->discoveredSkillDirs === null) {
            return true;
        }

        $skillDir = (string) $skill->sourceDir;
        foreach ($donor->discoveredSkillDirs as $dir) {
            if ((string) $dir === $skillDir) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, list<InstalledSkill>> $installedByKey
     */
    private function resolveStatus(Skill $skill, array $installedByKey): SyncStatus
    {
        $installed = $this->findInstalled($skill, $installedByKey[SkillConflict::collisionKey($skill->name)] ?? []);
        if ($installed === null) {
            return SyncStatus::NotSynced;
        }

        return $this->drift->differs($skill->sourceDir, $installed->dir)
            ? SyncStatus::Drift
            : SyncStatus::InSync;
    }

    /**
     * The installed directory `$skill` is written to: the one with its exact
     * name, or one spelled in another case that a case-insensitive target
     * stores it in. Only the file system can tell the second case apart: on a
     * case-sensitive target such a directory is a different skill.
     *
     * @param list<InstalledSkill> $candidates installed skills sharing the skill's collision key
     */
    private function findInstalled(Skill $skill, array $candidates): ?InstalledSkill
    {
        foreach ($candidates as $installed) {
            if ($installed->name === $skill->name) {
                return $installed;
            }
        }
        foreach ($candidates as $installed) {
            if (PathContainment::isSame($installed->dir->parent()->join($skill->name), $installed->dir)) {
                return $installed;
            }
        }

        return null;
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
     * @param array<string, list<non-empty-string>> $conflictMap
     *
     * @return non-empty-string|null
     *
     * @psalm-pure
     */
    private function resolveConflictPartner(Skill $skill, array $conflictMap): ?string
    {
        $packages = $conflictMap[SkillConflict::collisionKey($skill->name)] ?? null;
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

        // Every contender is this package: two of its own skills share one
        // directory name, whether spelled alike or differing only by case.
        return $skill->packageName;
    }

    /**
     * @param list<\LLM\Skills\Discovery\MalformedDonor> $malformed
     * @param list<\LLM\Skills\Discovery\SourceFailure> $failures `sources[]` entries that never
     *         became donors; listed under `source-failed` so a declared donor that could not be
     *         resolved or fetched is visible in the report instead of being absent from it
     * @param list<string> $enumerationWarnings raw text from {@see SkillEnumerator}
     * @param list<non-empty-string> $droppedDonors the donors whose source directory was missing
     *         or unreadable; only their warnings mean the donor itself was skipped
     * @param list<\LLM\Skills\Discovery\SkillNotice> $refusedSkills one per skill refused for its
     *         directory name
     * @param list<VendorConfig> $unactivatedDiscoverable packages that ship undeclared skills
     *         (found by {@see \LLM\Skills\Discovery\SkillTreeScanner}) but were not promoted
     *         into the run (because `--discovery` is off); listed under `not-declared` so the
     *         user sees their names alongside the actionable hint
     *
     * @return list<SkippedDonor>
     *
     * @psalm-mutation-free
     */
    private function buildSkippedList(
        array $malformed,
        array $failures,
        array $enumerationWarnings,
        array $droppedDonors,
        array $refusedSkills,
        SyncPlan $plan,
        array $unactivatedDiscoverable,
    ): array {
        $result = [];

        // Source failures come first: a donor the user declared and
        // cannot get is more actionable than one that merely needs a
        // trust decision or a flag.
        foreach ($failures as $failure) {
            $result[] = new SkippedDonor(
                packageName: $failure->label,
                reason: SkipReason::SourceFailed,
                detail: $failure->summary,
            );
        }

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

        // A dropped donor's warning says its source dir does not exist or is
        // unreadable; parse the package name back out to match it to a typed
        // reason. Other warnings, such as a renamed root skill or an allowlist
        // typo, leave the donor installed and are no reason to list it here.
        $dropped = \array_fill_keys($droppedDonors, true);
        foreach ($enumerationWarnings as $warning) {
            $name = $this->extractPackageName($warning);
            if ($name === null || !isset($dropped[$name])) {
                continue;
            }
            if (!\str_starts_with($warning, $name . ': source directory "')) {
                continue;
            }
            $result[] = new SkippedDonor(
                packageName: $name,
                reason: SkipReason::SourceMissing,
                detail: $warning,
            );
        }

        foreach ($refusedSkills as $notice) {
            $result[] = new SkippedDonor(
                packageName: $notice->packageName,
                reason: SkipReason::SkillRefused,
                detail: $notice->message,
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
