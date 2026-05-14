<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Sync;

use Internal\Path;
use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\TrustedVendors;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Config\VendorPattern;
use LLM\Skills\Sync\SyncPlanner;
use Testo\Assert;
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
        Assert::same($plan->untrustedNamedDonors, []);
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

    public function untrustedDonorMatchedByPositionalFilterGoesToUntrustedNamed(): void
    {
        $donor = $this->donor('evil/payload');
        $plan = $this->planner()->plan(
            donors: [$donor],
            project: ProjectConfig::default(),
            options: $this->optionsWithFilters('evil/payload'),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->projectRoot(),
        );

        Assert::same($plan->approvedDonors, []);
        Assert::same($plan->skippedUntrustedNames, []);
        Assert::same(\count($plan->untrustedNamedDonors), 1);
        Assert::same($plan->untrustedNamedDonors[0]->packageName, 'evil/payload');
    }

    public function trustedDonorIsApprovedEvenWhenAlsoMatchedByFilter(): void
    {
        // Belt-and-suspenders: an explicit positional arg must not demote a
        // trusted donor to "needs prompt".
        $donor = $this->donor('acme/skills-basic');
        $plan = $this->planner()->plan(
            donors: [$donor],
            project: ProjectConfig::default(),
            options: $this->optionsWithFilters('acme/skills-basic'),
            builtin: TrustedVendors::fromStrings('acme/*'),
            projectRoot: $this->projectRoot(),
        );

        Assert::same(\count($plan->approvedDonors), 1);
        Assert::same($plan->untrustedNamedDonors, []);
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

    public function absoluteTargetOverrideIsUsedAsIs(): void
    {
        $options = new SyncOptions(
            packageFilters: [],
            extraTrusted: [],
            targetOverride: '/abs/skills',
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
            $this->normalizePath('/abs/skills'),
        );
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
        Assert::same($plan->untrustedNamedDonors, []);
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
