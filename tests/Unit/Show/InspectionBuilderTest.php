<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Show;

use Internal\Path;
use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Discovery\DonorDiscoveryResult;
use LLM\Skills\Discovery\Provider\DonorProvider;
use LLM\Skills\Discovery\Provider\ProviderId;
use LLM\Skills\Show\InspectionBuilder;
use LLM\Skills\Show\InspectionReport;
use LLM\Skills\Show\SkippedDonor;
use LLM\Skills\Show\SkipReason;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * {@see InspectionBuilder} driven by a stub {@see DonorProvider}.
 */
#[Test]
final class InspectionBuilderTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-show-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function listsASkillMarkedInternalAsSkippedWithItsReason(): void
    {
        $report = $this->build($this->donor(ProviderId::GITHUB));

        Assert::same($this->listedSkills($report), ['pdf']);
        $internal = \array_values(\array_filter(
            $report->skipped,
            static fn(SkippedDonor $row): bool => $row->reason === SkipReason::Internal,
        ));
        Assert::count($internal, 1);
        Assert::same($internal[0]->packageName, 'acme/tools');
        Assert::same($internal[0]->detail, 'skill "release": marked internal, not installed');
    }

    public function namesAnInternalSkillByItsFrontmatterNameAndItsDirectory(): void
    {
        $report = $this->build($this->donor(ProviderId::GITHUB, internalDir: 'release-dir'));

        $internal = $this->internalRows($report);
        Assert::count($internal, 1);
        Assert::same(
            $internal[0]->detail,
            'skill "release" (directory "release-dir"): marked internal, not installed',
        );
    }

    public function saysAnInternalSkillAlreadyInTheTargetIsNoLongerSynced(): void
    {
        $installed = $this->tmp . '/.agents/skills/release';
        \mkdir($installed, 0o777, true);
        \file_put_contents($installed . '/SKILL.md', "---\nname: release\n---\n");

        $report = $this->build($this->donor(ProviderId::GITHUB));

        $internal = $this->internalRows($report);
        Assert::count($internal, 1);
        Assert::same(
            $internal[0]->detail,
            'skill "release": marked internal, not synced; the target\'s directory of its name stays'
            . ' until an unscoped clean sync',
        );
    }

    public function listsAnInternalSkillOfADirSourceAsInstallable(): void
    {
        $report = $this->build($this->donor(ProviderId::DIR));

        Assert::same($this->listedSkills($report), ['pdf', 'release']);
        Assert::same($report->skipped, []);
    }

    private function build(VendorConfig $donor): InspectionReport
    {
        $provider = new class([$donor]) implements DonorProvider {
            /**
             * @param list<VendorConfig> $donors
             */
            public function __construct(
                private readonly array $donors,
            ) {}

            public function isActive(Path $projectRoot): bool
            {
                return true;
            }

            public function discover(Path $projectRoot, ?Path $target = null): DonorDiscoveryResult
            {
                return new DonorDiscoveryResult(donors: $this->donors, warnings: []);
            }

            public function directDependencies(Path $projectRoot): array
            {
                return [];
            }
        };

        return (new InspectionBuilder())->build(
            Path::create($this->tmp),
            $provider,
            ProjectConfig::default(),
            SyncOptions::default(),
        );
    }

    /**
     * @return list<SkippedDonor>
     */
    private function internalRows(InspectionReport $report): array
    {
        return \array_values(\array_filter(
            $report->skipped,
            static fn(SkippedDonor $row): bool => $row->reason === SkipReason::Internal,
        ));
    }

    /**
     * @param non-empty-string $provenance
     * @param non-empty-string $internalDir directory of the internal skill named `release`
     */
    private function donor(string $provenance, string $internalDir = 'release'): VendorConfig
    {
        $root = $this->tmp . '/donor';
        \mkdir($root . '/skills/pdf', 0o777, true);
        \mkdir($root . '/skills/' . $internalDir, 0o777, true);
        \file_put_contents($root . '/skills/pdf/SKILL.md', "---\nname: pdf\n---\n");
        \file_put_contents(
            $root . '/skills/' . $internalDir . '/SKILL.md',
            "---\nname: release\nmetadata:\n  internal: true\n---\n",
        );

        return new VendorConfig(
            packageName: 'acme/tools',
            packageRoot: Path::create($root),
            source: 'skills',
            provenance: $provenance,
            implicitTrust: true,
        );
    }

    /**
     * @return list<non-empty-string>
     */
    private function listedSkills(InspectionReport $report): array
    {
        $names = [];
        foreach ($report->donors as $donor) {
            foreach ($donor->skills as $skill) {
                $names[] = $skill->skill->name;
            }
        }
        \sort($names);

        return $names;
    }
}
