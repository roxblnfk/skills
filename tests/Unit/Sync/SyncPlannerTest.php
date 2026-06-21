<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Sync;

use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\TrustedVendors;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Config\VendorPattern;
use LLM\Skills\Sync\SyncPlanner;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class SyncPlannerTest
{
    /**
     * Most tests share the same project root and builtin trust list. Helpers
     * keep test bodies focused on the decision under test.
     */
    public function trustedAutoDiscoveredDonorIsApproved(): void
    {
        $donor = $this->donor('acme/skills-basic');
        $plan = $this->planner()->plan(
            donors: [$donor],
            project: ProjectConfig::default(),
            options: SyncOptions::default(),
            builtin: TrustedVendors::fromStrings('acme/*'),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(\count($plan->approvedDonors), 1);
        Assert::same($plan->approvedDonors[0]->packageName, 'acme/skills-basic');
        Assert::same($plan->skippedUntrustedNames, []);
    }

    public function untrustedAutoDiscoveredDonorIsSkipped(): void
    {
        $donor = $this->donor('evil/payload');
        $plan = $this->planner()->plan(
            donors: [$donor],
            project: ProjectConfig::default(),
            options: SyncOptions::default(),
            builtin: TrustedVendors::fromStrings('acme/*'),
            projectRoot: $this->projectRoot(),
        );

        Assert::same($plan->approvedDonors, []);
        Assert::same($plan->skippedUntrustedNames, ['evil/payload']);
    }

    public function untrustedDonorMatchedByPositionalFilterIsImplicitlyTrusted(): void
    {
        // Naming a package as a positional arg means "I want this synced",
        // which short-circuits the trust check. No prompt, no warning.
        $donor = $this->donor('evil/payload');
        $plan = $this->planner()->plan(
            donors: [$donor],
            project: ProjectConfig::default(),
            options: $this->optionsWithFilters('evil/payload'),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(\count($plan->approvedDonors), 1);
        Assert::same($plan->approvedDonors[0]->packageName, 'evil/payload');
        Assert::same($plan->skippedUntrustedNames, []);
    }

    public function vendorWildcardFilterImplicitlyTrustsEveryMatchingDonor(): void
    {
        // `evil/*` as a positional grants implicit trust to every package
        // under the vendor — exactly the same shortcut as naming each.
        $a = $this->donor('evil/payload');
        $b = $this->donor('evil/other');
        $c = $this->donor('acme/skills-basic');
        $plan = $this->planner()->plan(
            donors: [$a, $b, $c],
            project: ProjectConfig::default(),
            options: $this->optionsWithFilters('evil/*'),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(\count($plan->approvedDonors), 2);
        $approvedNames = \array_map(static fn($d) => $d->packageName, $plan->approvedDonors);
        \sort($approvedNames);
        Assert::same($approvedNames, ['evil/other', 'evil/payload']);
    }

    public function trustedDonorIsApprovedEvenWhenAlsoMatchedByFilter(): void
    {
        // Belt-and-suspenders: an explicit positional arg must not demote
        // an already-trusted donor.
        $donor = $this->donor('acme/skills-basic');
        $plan = $this->planner()->plan(
            donors: [$donor],
            project: ProjectConfig::default(),
            options: $this->optionsWithFilters('acme/skills-basic'),
            builtin: TrustedVendors::fromStrings('acme/*'),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(\count($plan->approvedDonors), 1);
    }

    public function projectTrustExtendsBuiltinByDefault(): void
    {
        $donor = $this->donor('myorg/skills');
        $plan = $this->planner()->plan(
            donors: [$donor],
            project: new ProjectConfig(
                target: '.claude/skills',
                trusted: TrustedVendors::fromStrings('myorg/*'),
                trustedReplace: false,
            ),
            options: SyncOptions::default(),
            builtin: TrustedVendors::fromStrings('acme/*'), // doesn't cover myorg/*
            projectRoot: $this->projectRoot(),
        );

        Assert::same(\count($plan->approvedDonors), 1);
    }

    public function projectTrustReplacesBuiltinWhenReplaceFlagSet(): void
    {
        // With trustedReplace: true, the builtin list is ignored. A donor only
        // matching the builtin must NOT be approved.
        $donor = $this->donor('acme/skills-basic');
        $plan = $this->planner()->plan(
            donors: [$donor],
            project: new ProjectConfig(
                target: '.claude/skills',
                trusted: TrustedVendors::fromStrings('myorg/*'),
                trustedReplace: true,
            ),
            options: SyncOptions::default(),
            builtin: TrustedVendors::fromStrings('acme/*'),
            projectRoot: $this->projectRoot(),
        );

        Assert::same($plan->approvedDonors, []);
        Assert::same($plan->skippedUntrustedNames, ['acme/skills-basic']);
    }

    public function extraTrustedFromCliApprovesOtherwiseUntrustedDonor(): void
    {
        $donor = $this->donor('evil/payload');
        $options = new SyncOptions(
            packageFilters: [],
            extraTrusted: [VendorPattern::fromString('evil/payload')],
            targetOverride: null,
            interactive: false,
        );
        $plan = $this->planner()->plan(
            donors: [$donor],
            project: ProjectConfig::default(),
            options: $options,
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(\count($plan->approvedDonors), 1);
    }

    public function packageFilterDropsNonMatchingDonors(): void
    {
        $a = $this->donor('acme/keep');
        $b = $this->donor('acme/drop');
        $plan = $this->planner()->plan(
            donors: [$a, $b],
            project: ProjectConfig::default(),
            options: $this->optionsWithFilters('acme/keep'),
            builtin: TrustedVendors::fromStrings('acme/*'),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(\count($plan->approvedDonors), 1);
        Assert::same($plan->approvedDonors[0]->packageName, 'acme/keep');
    }

    public function filteredOutDonorsArePreservedForShow(): void
    {
        // Donors discovered but rejected by the positional pattern should
        // surface in the plan so `skills:show` can list them under
        // `Skipped: ... filtered-out`. They are never copied.
        $kept = $this->donor('acme/keep');
        $rejected = $this->donor('acme/drop');
        $plan = $this->planner()->plan(
            donors: [$kept, $rejected],
            project: ProjectConfig::default(),
            options: $this->optionsWithFilters('acme/keep'),
            builtin: TrustedVendors::fromStrings('acme/*'),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(\count($plan->filteredOutDonors), 1);
        Assert::same($plan->filteredOutDonors[0]->packageName, 'acme/drop');
    }

    public function filteredOutIsEmptyWhenNoPositionalFilterIsGiven(): void
    {
        $plan = $this->planner()->plan(
            donors: [$this->donor('acme/keep'), $this->donor('acme/drop')],
            project: ProjectConfig::default(),
            options: SyncOptions::default(),
            builtin: TrustedVendors::fromStrings('acme/*'),
            projectRoot: $this->projectRoot(),
        );

        Assert::same($plan->filteredOutDonors, []);
    }

    public function targetDefaultsToProjectConfigJoinedAgainstProjectRoot(): void
    {
        $plan = $this->planner()->plan(
            donors: [],
            project: ProjectConfig::default(),
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(
            $this->normalizePath((string) $plan->target),
            $this->normalizePath('/some/project/.agents/skills'),
        );
    }

    public function cliTargetOverrideTakesPrecedenceOverProjectConfig(): void
    {
        $options = new SyncOptions(
            packageFilters: [],
            extraTrusted: [],
            targetOverride: 'custom/skills',
            interactive: false,
        );
        $plan = $this->planner()->plan(
            donors: [],
            project: ProjectConfig::default(),
            options: $options,
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(
            $this->normalizePath((string) $plan->target),
            $this->normalizePath('/some/project/custom/skills'),
        );
    }

    public function absoluteTargetInsideProjectRootIsAccepted(): void
    {
        // Absolute paths are honoured as-is, *but* they must still
        // resolve inside the project root. A path that happens to be
        // expressed absolutely yet lives under the project is fine.
        $options = new SyncOptions(
            packageFilters: [],
            extraTrusted: [],
            targetOverride: '/some/project/abs/skills',
            interactive: false,
        );
        $plan = $this->planner()->plan(
            donors: [],
            project: ProjectConfig::default(),
            options: $options,
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(
            $this->normalizePath((string) $plan->target),
            $this->normalizePath('/some/project/abs/skills'),
        );
    }

    public function absoluteTargetOutsideProjectRootIsRejected(): void
    {
        // Footgun guard: a CLI `--target=/etc/passwd` or a typo in
        // composer.json must not be able to direct writes outside the
        // project tree. Symmetric with the donor-side `source` escape
        // check ({@see \LLM\Skills\Config\Mapper\VendorConfigMapper}).
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('outside the project root');

        $options = new SyncOptions(
            packageFilters: [],
            extraTrusted: [],
            targetOverride: '/etc/passwd',
            interactive: false,
        );
        $this->planner()->plan(
            donors: [],
            project: ProjectConfig::default(),
            options: $options,
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );
    }

    public function targetWithDotDotEscapeIsRejected(): void
    {
        // Relative paths that collapse to a location outside the
        // project root are caught after Path normalises the `..`
        // segments. The error names both the raw value and the
        // resolved location so the user can find the entry.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('outside the project root');

        $project = new ProjectConfig(
            target: '../escape',
            trusted: TrustedVendors::empty(),
            trustedReplace: false,
        );
        $this->planner()->plan(
            donors: [],
            project: $project,
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );
    }

    public function pathFromRootReanchorsTargetToTheVerifiedOuterRoot(): void
    {
        // projectRoot is /some/project; declaring path-from-root "project"
        // climbs one level to /some and resolves a plain (non-`..`) target
        // against it — reaching a shared monorepo-level directory without
        // any escape syntax.
        $project = new ProjectConfig(
            target: '.agents/skills',
            trusted: TrustedVendors::empty(),
            trustedReplace: false,
            pathFromRoot: 'project',
        );

        $plan = $this->planner()->plan(
            donors: [],
            project: $project,
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(
            $this->normalizePath((string) $plan->target),
            $this->normalizePath('/some/.agents/skills'),
        );
    }

    public function absoluteTargetInsideContainmentRootIsAccepted(): void
    {
        // With the root re-anchored to /some, an absolute target that lives
        // inside that root is honoured — but it must stay inside it (see
        // pathFromRootDoesNotPermitEscapingTheOuterRoot).
        $options = new SyncOptions(
            packageFilters: [],
            extraTrusted: [],
            targetOverride: '/some/shared/skills',
            interactive: false,
        );
        $project = new ProjectConfig(
            target: ProjectConfig::DEFAULT_TARGET,
            trusted: TrustedVendors::empty(),
            trustedReplace: false,
            pathFromRoot: 'project',
        );

        $plan = $this->planner()->plan(
            donors: [],
            project: $project,
            options: $options,
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(
            $this->normalizePath((string) $plan->target),
            $this->normalizePath('/some/shared/skills'),
        );
    }

    public function pathFromRootDoesNotPermitEscapingTheOuterRoot(): void
    {
        // path-from-root widens the boundary, it does not remove it: a
        // target outside the re-anchored root (/some) is still rejected.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('outside the project root');

        $project = new ProjectConfig(
            target: '/tmp/skills',
            trusted: TrustedVendors::empty(),
            trustedReplace: false,
            pathFromRoot: 'project',
        );

        $this->planner()->plan(
            donors: [],
            project: $project,
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );
    }

    public function pathFromRootThatDoesNotMatchProjectLocationIsRejected(): void
    {
        // The declared suffix must reconstruct the real project location;
        // /some/project does not end with "elsewhere", so the climb is
        // refused loudly instead of anchoring writes to a wrong ancestor.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('does not match the project location');

        $project = new ProjectConfig(
            target: '.agents/skills',
            trusted: TrustedVendors::empty(),
            trustedReplace: false,
            pathFromRoot: 'elsewhere',
        );

        $this->planner()->plan(
            donors: [],
            project: $project,
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );
    }

    public function containmentRootTargetIsRejected(): void
    {
        // Even with path-from-root, the target must not be the root itself.
        // target "." resolves to the re-anchored root /some.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('must not be the project root itself');

        $project = new ProjectConfig(
            target: '.',
            trusted: TrustedVendors::empty(),
            trustedReplace: false,
            pathFromRoot: 'project',
        );

        $this->planner()->plan(
            donors: [],
            project: $project,
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );
    }

    public function aliasesDefaultToEmptyListWhenNeitherConfigNorCliProvidesThem(): void
    {
        $plan = $this->planner()->plan(
            donors: [],
            project: ProjectConfig::default(),
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );

        Assert::same($plan->aliases, []);
    }

    public function aliasesFromProjectConfigAreResolvedAgainstProjectRoot(): void
    {
        $project = ProjectConfig::default()->withAliases(['.claude/skills', '.cursor/skills']);
        $plan = $this->planner()->plan(
            donors: [],
            project: $project,
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(\count($plan->aliases), 2);
        Assert::same(
            $this->normalizePath((string) $plan->aliases[0]),
            $this->normalizePath('/some/project/.claude/skills'),
        );
        Assert::same(
            $this->normalizePath((string) $plan->aliases[1]),
            $this->normalizePath('/some/project/.cursor/skills'),
        );
    }

    public function absoluteAliasInsideProjectRootIsAccepted(): void
    {
        // Absolute paths must not be joined to the project root, exactly
        // like {@see SyncPlanner::resolveTarget()} treats them — and
        // they must still live inside the project tree.
        $project = ProjectConfig::default()->withAliases(['/some/project/abs/alias']);
        $plan = $this->planner()->plan(
            donors: [],
            project: $project,
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(
            $this->normalizePath((string) $plan->aliases[0]),
            $this->normalizePath('/some/project/abs/alias'),
        );
    }

    public function absoluteAliasOutsideProjectRootIsRejected(): void
    {
        // Same containment guard as for `target`. Aliases create
        // junctions/symlinks, so a path outside the project tree
        // would expose arbitrary filesystem locations through the
        // project's own directory structure.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('outside the project root');

        $project = ProjectConfig::default()->withAliases(['/tmp/escape']);
        $this->planner()->plan(
            donors: [],
            project: $project,
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );
    }

    public function aliasWithDotDotEscapeIsRejected(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('outside the project root');

        $project = ProjectConfig::default()->withAliases(['../escape']);
        $this->planner()->plan(
            donors: [],
            project: $project,
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );
    }

    public function aliasEscapingContainmentRootIsRejectedEvenWithPathFromRoot(): void
    {
        // Aliases are confined to the same re-anchored root as the target.
        // With path-from-root "project" the root is /some; the target is
        // legitimately inside it, but the alias `../.claude/skills`
        // resolves above /some and is rejected.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('outside the project root');

        $project = new ProjectConfig(
            target: '.agents/skills',
            trusted: TrustedVendors::empty(),
            trustedReplace: false,
            aliases: ['../.claude/skills'],
            pathFromRoot: 'project',
        );
        $this->planner()->plan(
            donors: [],
            project: $project,
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );
    }

    public function aliasOverrideReplacesProjectConfigEntirely(): void
    {
        // Passing `--alias` at all is an explicit takeover, never a merge —
        // the planner must drop the project's aliases and use only the CLI list.
        $project = ProjectConfig::default()->withAliases(['.claude/skills']);
        $options = new SyncOptions(
            packageFilters: [],
            extraTrusted: [],
            targetOverride: null,
            interactive: false,
            aliasOverrides: ['.cursor/skills'],
        );
        $plan = $this->planner()->plan(
            donors: [],
            project: $project,
            options: $options,
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(\count($plan->aliases), 1);
        Assert::same(
            $this->normalizePath((string) $plan->aliases[0]),
            $this->normalizePath('/some/project/.cursor/skills'),
        );
    }

    public function aliasResolvingToTargetThrows(): void
    {
        // `./.claude/skills` and `.claude/skills` collapse to the same
        // absolute path; the planner must catch the equality even though
        // the raw strings differ from the configured target.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('alias');

        $project = new ProjectConfig(
            target: '.claude/skills',
            trusted: TrustedVendors::empty(),
            trustedReplace: false,
            aliases: ['./.claude/skills'],
        );
        $this->planner()->plan(
            donors: [],
            project: $project,
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );
    }

    public function duplicateAliasesAfterResolutionThrow(): void
    {
        // `.claude/skills` and `./.claude/skills` are lexically distinct
        // but collapse to the same absolute path. The mapper's lexical
        // pass cannot tell — the planner's resolved-path pass must.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('duplicates');

        $project = ProjectConfig::default()->withAliases([
            '.claude/skills',
            './.claude/skills',
        ]);
        $this->planner()->plan(
            donors: [],
            project: $project,
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );
    }

    public function directDependencyIsImplicitlyTrustedWithoutAnyTrustPattern(): void
    {
        // A package listed in the consumer's root `require` (or
        // `require-dev`) does not need an explicit trust entry — the
        // act of depending on it is already an act of trust.
        $donor = $this->donor('acme/skills-basic');
        $plan = $this->planner()->plan(
            donors: [$donor],
            project: ProjectConfig::default(),
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
            directDependencies: ['acme/skills-basic'],
        );

        Assert::same(\count($plan->approvedDonors), 1);
        Assert::same($plan->skippedUntrustedNames, []);
    }

    public function transitiveDependencyStillRequiresTrustPattern(): void
    {
        // Direct-dep trust only short-circuits packages declared at the
        // root level. Anything pulled in transitively must still pass
        // through the trust list.
        $donor = $this->donor('evil/payload');
        $plan = $this->planner()->plan(
            donors: [$donor],
            project: ProjectConfig::default(),
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
            directDependencies: ['acme/skills-basic'],
        );

        Assert::same($plan->approvedDonors, []);
        Assert::same($plan->skippedUntrustedNames, ['evil/payload']);
    }

    public function trustedReplaceDisablesDirectDependencyTrust(): void
    {
        // `trusted-replace: true` is "I curate trust explicitly" — it
        // turns off built-in trust and direct-dep trust alike, leaving
        // only the project's own list (and `--trust=`).
        $donor = $this->donor('acme/skills-basic');
        $plan = $this->planner()->plan(
            donors: [$donor],
            project: new ProjectConfig(
                target: '.agents/skills',
                trusted: TrustedVendors::empty(),
                trustedReplace: true,
            ),
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
            directDependencies: ['acme/skills-basic'],
        );

        Assert::same($plan->approvedDonors, []);
        Assert::same($plan->skippedUntrustedNames, ['acme/skills-basic']);
    }

    public function directDependencyTrustIgnoredWhenPositionalFilterPresent(): void
    {
        // Positional filters short-circuit every trust source — the
        // direct-dep list is no different. Filter-rejected direct deps
        // are dropped, not silently approved.
        $kept = $this->donor('acme/keep');
        $rejected = $this->donor('acme/drop');
        $plan = $this->planner()->plan(
            donors: [$kept, $rejected],
            project: ProjectConfig::default(),
            options: $this->optionsWithFilters('acme/keep'),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
            directDependencies: ['acme/keep', 'acme/drop'],
        );

        Assert::same(\count($plan->approvedDonors), 1);
        Assert::same($plan->approvedDonors[0]->packageName, 'acme/keep');
        Assert::same(\count($plan->filteredOutDonors), 1);
    }

    public function emptyDonorsListYieldsEmptyApprovedAndEmptySkipped(): void
    {
        $plan = $this->planner()->plan(
            donors: [],
            project: ProjectConfig::default(),
            options: SyncOptions::default(),
            builtin: TrustedVendors::fromStrings('acme/*'),
            projectRoot: $this->projectRoot(),
        );

        Assert::same($plan->approvedDonors, []);
        Assert::same($plan->skippedUntrustedNames, []);
    }

    public function implicitTrustDonorIsApprovedWithoutAnyTrustPattern(): void
    {
        // A `remote[]` donor carries `implicitTrust = true` and
        // the planner must approve it without consulting the trust list or
        // the direct-dependency short-circuit. Reproduces copilot review
        // #1 on PR #15: with an empty trust list and no direct deps, the
        // donor would otherwise be silently skipped, breaking the default
        // `skills:add` → auto-sync flow.
        $donor = $this->donor('external/skills')->asImplicitlyTrusted();
        $plan = $this->planner()->plan(
            donors: [$donor],
            project: ProjectConfig::default(),
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(\count($plan->approvedDonors), 1);
        Assert::same($plan->approvedDonors[0]->packageName, 'external/skills');
        Assert::true($plan->approvedDonors[0]->implicitTrust);
        Assert::same($plan->skippedUntrustedNames, []);
    }

    public function implicitTrustSurvivesTrustedReplaceModeAndEmptyDirectDeps(): void
    {
        // `trusted-replace: true` wipes builtin + direct-dep trust. The
        // implicit-trust flag is rooted in the user's explicit declaration
        // (the `remote[]` entry) and stays in force regardless — replace
        // mode tightens trust for local discoveries, not for entries the
        // user typed verbatim.
        $project = new ProjectConfig(
            target: '.agents/skills',
            trusted: TrustedVendors::empty(),
            trustedReplace: true,
        );
        $donor = $this->donor('external/skills')->asImplicitlyTrusted();
        $plan = $this->planner()->plan(
            donors: [$donor],
            project: $project,
            options: SyncOptions::default(),
            builtin: TrustedVendors::fromStrings('totally-irrelevant/*'),
            projectRoot: $this->projectRoot(),
            directDependencies: [],
        );

        Assert::same(\count($plan->approvedDonors), 1);
        Assert::same($plan->approvedDonors[0]->packageName, 'external/skills');
    }

    public function implicitTrustCoexistsWithStandardTrustForOtherDonors(): void
    {
        // Mixed batch: one implicit-trust donor (remote) + one untrusted
        // local donor. Implicit one approves, the other is skipped — the
        // flag is per-donor, not a global override.
        $remote = $this->donor('external/skills')->asImplicitlyTrusted();
        $local = $this->donor('untrusted/local');
        $plan = $this->planner()->plan(
            donors: [$remote, $local],
            project: ProjectConfig::default(),
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(\count($plan->approvedDonors), 1);
        Assert::same($plan->approvedDonors[0]->packageName, 'external/skills');
        Assert::same($plan->skippedUntrustedNames, ['untrusted/local']);
    }

    private function planner(): SyncPlanner
    {
        return new SyncPlanner();
    }

    private function projectRoot(): Path
    {
        return Path::create('/some/project');
    }

    /**
     * @param non-empty-string $packageName
     */
    private function donor(string $packageName): VendorConfig
    {
        return new VendorConfig(
            packageName: $packageName,
            packageRoot: Path::create('/vendor/' . $packageName),
            source: '.claude/skills',
        );
    }

    /**
     * @param non-empty-string ...$patterns
     */
    private function optionsWithFilters(string ...$patterns): SyncOptions
    {
        return new SyncOptions(
            packageFilters: \array_map(
                static fn(string $p) => VendorPattern::fromString($p),
                \array_values($patterns),
            ),
            extraTrusted: [],
            targetOverride: null,
            interactive: false,
        );
    }

    /**
     * Path joining produces native separators (backslash on Windows). Tests
     * compare against POSIX-style literals, so normalize both sides.
     */
    private function normalizePath(string $path): string
    {
        return \str_replace('\\', '/', $path);
    }
}
