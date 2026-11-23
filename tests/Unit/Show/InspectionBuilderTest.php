<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Show;

use Internal\Path;
use LLM\Skills\Config\LayerChanges;
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
use LLM\Skills\Show\SyncStatus;
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

    public function namesSkillsJsonForADonorOfASharedSource(): void
    {
        $donor = $this->donor(ProviderId::DIR)->withSourceIdentity('dir||./team');

        $report = $this->build($donor);

        Assert::same($report->donors[0]->declaredIn, 'skills.json');
    }

    public function namesTheLayerThatDeclaresTheSource(): void
    {
        $donor = $this->donor(ProviderId::DIR)->withSourceIdentity('dir||./mine');
        $layer = new LayerChanges('skills.local.json', sourceIdentities: ['dir||./mine']);

        $report = $this->build($donor, [$layer]);

        Assert::same($report->donors[0]->declaredIn, 'skills.local.json');
    }

    public function namesNoFileForADonorOutsideTheProjectSources(): void
    {
        $report = $this->build($this->donor(ProviderId::GITHUB));

        Assert::null($report->donors[0]->declaredIn);
    }

    public function aSkillHoldingAnotherSkillsSourceIsNotDriftForWhatTheSyncKeeps(): void
    {
        // The sync merges into `pdf` instead of replacing it, so `pack/` survives every sync.
        $installed = $this->tmp . '/.agents/skills/pdf';
        \mkdir($installed . '/pack/skills/inner', 0o777, true);
        \file_put_contents($installed . '/SKILL.md', "---\nname: pdf\n---\n");
        \file_put_contents($installed . '/pack/skills/inner/SKILL.md', "---\nname: inner\n---\n");
        $packed = new VendorConfig(
            packageName: 'acme/pack',
            packageRoot: Path::create($installed . '/pack'),
            source: 'skills',
            provenance: ProviderId::DIR,
            implicitTrust: true,
        );

        $report = $this->build([$this->donor(ProviderId::DIR), $packed]);

        Assert::same($this->statusOf($report, 'pdf'), SyncStatus::InSync);
    }

    public function anExtraFileInAReplacedSkillIsDrift(): void
    {
        $installed = $this->tmp . '/.agents/skills/pdf';
        \mkdir($installed, 0o777, true);
        \file_put_contents($installed . '/SKILL.md', "---\nname: pdf\n---\n");
        \file_put_contents($installed . '/notes.md', 'mine');

        $report = $this->build($this->donor(ProviderId::DIR));

        Assert::same($this->statusOf($report, 'pdf'), SyncStatus::Drift);
    }

    /**
     * @param VendorConfig|list<VendorConfig> $donors
     * @param list<LayerChanges> $layers
     */
    private function build(VendorConfig|array $donors, array $layers = []): InspectionReport
    {
        $provider = new class(\is_array($donors) ? $donors : [$donors]) implements DonorProvider {
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
            $layers,
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

    private function statusOf(InspectionReport $report, string $name): ?SyncStatus
    {
        foreach ($report->donors as $donor) {
            foreach ($donor->skills as $skill) {
                if ($skill->skill->name === $name) {
                    return $skill->status;
                }
            }
        }

        return null;
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
