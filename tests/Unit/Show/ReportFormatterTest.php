<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Show;

use Internal\Path;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Discovery\Skill;
use LLM\Skills\Show\DonorInspection;
use LLM\Skills\Show\InspectionReport;
use LLM\Skills\Show\ReportFormatter;
use LLM\Skills\Show\SkillInspection;
use LLM\Skills\Show\SkippedDonor;
use LLM\Skills\Show\SkipReason;
use LLM\Skills\Show\SyncStatus;
use LLM\Skills\Show\TrustSource;
use Testo\Assert;
use Testo\Test;

#[Test]
final class ReportFormatterTest
{
    public function emitsPlaceholderWhenReportIsCompletelyEmpty(): void
    {
        $lines = (new ReportFormatter())->format(
            new InspectionReport(target: Path::create('/t'), donors: [], skipped: []),
        );

        Assert::same($lines, ['No donor packages found.']);
    }

    public function rendersTargetHeaderAtTheTopWhenAnythingIsRendered(): void
    {
        $donor = $this->donorInspection(
            packageName: 'acme/skills-basic',
            source: 'skills',
            trust: TrustSource::Project,
            skills: [['greeting', SyncStatus::InSync]],
        );

        $lines = (new ReportFormatter())->format(new InspectionReport(
            target: Path::create('/p/.agents/skills'),
            donors: [$donor],
            skipped: [],
        ));

        // The first line is the target header. The second is a blank
        // separator. The actual content starts at index 2.
        Assert::true(\str_starts_with($lines[0], 'Target:'));
        Assert::true(\str_contains($lines[0], '.agents/skills'));
        Assert::same($lines[1], '');
    }

    public function rendersVendorHeaderFollowedByPackageAndSkillRows(): void
    {
        $donor = $this->donorInspection(
            packageName: 'acme/skills-basic',
            source: '.claude/skills',
            trust: TrustSource::Project,
            skills: [
                ['greeting', SyncStatus::InSync],
                ['code-review', SyncStatus::NotSynced],
            ],
        );

        $joined = $this->renderJoined($donor);

        // Vendor and package names are wrapped in <fg=cyan> tags for the
        // terminal — the joined text must carry both the visible
        // substring and the colour-tag opener.
        Assert::true(\str_contains($joined, 'acme/'));
        Assert::true(\str_contains($joined, '<fg=cyan>acme/</>'));
        Assert::true(\str_contains($joined, 'skills-basic'));
        Assert::true(\str_contains($joined, '.claude/skills'));
        // The chip codes have one space on each side inside the
        // coloured block — see ReportFormatter::CHIP_*.
        Assert::true(\str_contains($joined, ' OK ') && \str_contains($joined, 'greeting'));
        Assert::true(\str_contains($joined, ' NEW ') && \str_contains($joined, 'code-review'));
    }

    public function rendersDriftMarkerWithModifiedSuffix(): void
    {
        $donor = $this->donorInspection(
            packageName: 'acme/skills-basic',
            source: '.claude/skills',
            trust: TrustSource::Project,
            skills: [['greeting', SyncStatus::Drift]],
        );

        $joined = $this->renderJoined($donor);

        Assert::true(\str_contains($joined, ' MOD '));
        Assert::true(\str_contains($joined, 'greeting'));
        Assert::true(\str_contains($joined, '(modified)'));
    }

    public function rendersDescriptionFromFrontmatterAsTheSecondColumn(): void
    {
        // The donor side feeds the formatter a description string (the
        // builder reads it from SKILL.md frontmatter); the formatter
        // just renders whatever it's given verbatim.
        $donor = $this->donorInspection(
            packageName: 'acme/skills-basic',
            source: 'skills',
            trust: TrustSource::Project,
            skills: [
                ['greeting', SyncStatus::InSync, null, 'Reply with a friendly greeting.'],
                ['silent', SyncStatus::InSync, null, null],
            ],
        );

        $joined = $this->renderJoined($donor);

        Assert::true(\str_contains($joined, 'Reply with a friendly greeting.'));
        // The skill without a description still appears, but no extra
        // text on the line beyond the chip + name.
        Assert::true(\str_contains($joined, 'silent'));
    }

    public function suffixIsRenderedAfterDescription(): void
    {
        // Drift modifier should follow the description, so the reader can
        // scan: status, name, what-it-is, why-this-matters.
        $donor = $this->donorInspection(
            packageName: 'acme/skills-basic',
            source: 'skills',
            trust: TrustSource::Project,
            skills: [['greeting', SyncStatus::Drift, null, 'A friendly greeting.']],
        );

        $joined = $this->renderJoined($donor);

        $descPos = \strpos($joined, 'A friendly greeting.');
        $suffixPos = \strpos($joined, '(modified)');
        Assert::true($descPos !== false);
        Assert::true($suffixPos !== false);
        \assert(\is_int($descPos) && \is_int($suffixPos));
        Assert::true($suffixPos > $descPos, 'modified suffix must come after description');
    }

    public function wrapsChipsInSymfonyOutputFormatterTagsForColouring(): void
    {
        // Decorated rendering relies on Symfony tags: the consumer's IO
        // strips them when --no-ansi is in effect. Asserting on the tag
        // openers guarantees we kept that contract.
        $donor = $this->donorInspection(
            packageName: 'acme/skills-basic',
            source: 'skills',
            trust: TrustSource::Project,
            skills: [
                ['ok-skill', SyncStatus::InSync],
                ['new-skill', SyncStatus::NotSynced],
                ['mod-skill', SyncStatus::Drift],
            ],
        );

        $joined = $this->renderJoined($donor);

        Assert::true(\str_contains($joined, '<bg=green'));
        Assert::true(\str_contains($joined, '<bg=cyan'));
        Assert::true(\str_contains($joined, '<bg=yellow'));
    }

    public function annotatesBuiltinTrustOnPackageRow(): void
    {
        $donor = $this->donorInspection(
            packageName: 'spiral/skills-demo',
            source: 'skills',
            trust: TrustSource::Builtin,
            skills: [['demo', SyncStatus::NotSynced]],
        );

        $joined = $this->renderJoined($donor);

        Assert::true(\str_contains($joined, '[via built-in trust]'));
    }

    public function doesNotAnnotateProjectOrCliTrust(): void
    {
        $project = $this->donorInspection(
            packageName: 'acme/skills-basic',
            source: 'skills',
            trust: TrustSource::Project,
            skills: [],
        );
        $cli = $this->donorInspection(
            packageName: 'beta/extra',
            source: 'skills',
            trust: TrustSource::Cli,
            skills: [],
        );

        $joined = $this->renderJoined($project, $cli);

        Assert::false(\str_contains($joined, '[via built-in trust]'));
    }

    public function marksConflictsInlineNextToTheLoserSide(): void
    {
        $donor = $this->donorInspection(
            packageName: 'acme/skills-basic',
            source: 'skills',
            trust: TrustSource::Project,
            skills: [['greeting', SyncStatus::NotSynced, 'clash/skills-conflict']],
        );

        $joined = $this->renderJoined($donor);

        // Conflict overrides byte-level state: we expect the red !! chip,
        // not OK/NEW/MOD, and the partner package named inline.
        Assert::true(\str_contains($joined, ' !! '));
        Assert::true(\str_contains($joined, '<bg=red'));
        Assert::true(\str_contains($joined, 'with clash/skills-conflict'));
    }

    public function rendersSkippedSectionWithReasonInTheChip(): void
    {
        // Skipped rows no longer carry a generic SKIP chip. The chip
        // text is the reason itself; colour signals severity. Vendor
        // breakage (malformed) gets red, trust-required (untrusted) gets
        // yellow.
        $report = new InspectionReport(
            target: Path::create('/t'),
            donors: [],
            skipped: [
                new SkippedDonor('evil/payload', SkipReason::Untrusted),
                new SkippedDonor(
                    'acme/skills-broken',
                    SkipReason::Malformed,
                    'extra.skills.source must be a non-empty string',
                ),
            ],
        );

        $lines = (new ReportFormatter())->format($report);
        $joined = \implode("\n", $lines);

        Assert::true(\str_contains($joined, 'Skipped:'));
        // No generic SKIP chip anywhere.
        Assert::false(\str_contains($joined, ' SKIP '));
        // Reasons appear as chip text, 1 space each side.
        Assert::true(\str_contains($joined, ' untrusted '));
        Assert::true(\str_contains($joined, ' malformed '));
        // Each reason carries the right colour tag.
        Assert::true(\str_contains($joined, '<bg=yellow;fg=black;options=bold> untrusted </>'));
        Assert::true(\str_contains($joined, '<bg=red;fg=white;options=bold> malformed </>'));
        // Package names get the same cyan highlight as the main listing.
        Assert::true(\str_contains($joined, '<fg=cyan>evil/payload'));
        Assert::true(\str_contains($joined, '<fg=cyan>acme/skills-broken'));
        // Detail still rendered after the package name for malformed.
        Assert::true(\str_contains($joined, 'extra.skills.source must be a non-empty string'));
    }

    public function skipChipColourReflectsSeverity(): void
    {
        $report = new InspectionReport(
            target: Path::create('/t'),
            donors: [],
            skipped: [
                new SkippedDonor('a/missing-src', SkipReason::SourceMissing),
                new SkippedDonor('b/untrusted', SkipReason::Untrusted),
                new SkippedDonor('c/filtered', SkipReason::FilteredOut),
                new SkippedDonor('d/not-declared', SkipReason::NotDeclared),
            ],
        );

        $joined = \implode("\n", (new ReportFormatter())->format($report));

        // source-missing is vendor breakage → red.
        Assert::true(\str_contains($joined, '<bg=red;fg=white;options=bold> source-missing </>'));
        // untrusted needs a trust decision → yellow.
        Assert::true(\str_contains($joined, '<bg=yellow;fg=black;options=bold> untrusted </>'));
        // User explicitly excluded → muted white.
        Assert::true(\str_contains($joined, '<bg=white;fg=black> filtered-out </>'));
        // Auto-discovery candidate the user has not opted in to → informational cyan.
        Assert::true(\str_contains($joined, '<bg=cyan;fg=black> not-declared </>'));
    }

    public function groupsMultiplePackagesUnderTheSameVendorBlock(): void
    {
        $a = $this->donorInspection(
            packageName: 'acme/skills-basic',
            source: 'skills-a',
            trust: TrustSource::Project,
            skills: [],
        );
        $b = $this->donorInspection(
            packageName: 'acme/skills-pro',
            source: 'skills-b',
            trust: TrustSource::Project,
            skills: [],
        );

        $lines = (new ReportFormatter())->format(new InspectionReport(
            target: Path::create('/t'),
            donors: [$a, $b],
            skipped: [],
        ));

        // Single vendor header (a line that contains `acme/` but no
        // `skills-` tail).
        $vendorHeaders = \array_filter(
            $lines,
            static fn(string $l) => \str_contains($l, 'acme/') && !\str_contains($l, 'skills-'),
        );
        Assert::same(\count($vendorHeaders), 1);
        // Both packages listed under it.
        $packages = \array_filter(
            $lines,
            static fn(string $l) => \str_contains($l, 'skills-') && \str_contains($l, '<fg=cyan>'),
        );
        Assert::same(\count($packages), 2);
    }

    private function renderJoined(DonorInspection ...$donors): string
    {
        $lines = (new ReportFormatter())->format(new InspectionReport(
            target: Path::create('/t'),
            donors: \array_values($donors),
            skipped: [],
        ));

        return \implode("\n", $lines);
    }

    /**
     * @param non-empty-string $packageName
     * @param non-empty-string $source
     * @param list<array{0: non-empty-string, 1: SyncStatus, 2?: non-empty-string|null, 3?: string|null}> $skills
     *        Each tuple = [name, status, optional conflictWith, optional description].
     */
    private function donorInspection(
        string $packageName,
        string $source,
        TrustSource $trust,
        array $skills,
    ): DonorInspection {
        $skillInspections = [];
        foreach ($skills as $tuple) {
            $skillInspections[] = new SkillInspection(
                skill: new Skill(
                    name: $tuple[0],
                    sourceDir: Path::create('/donor/' . $tuple[0]),
                    packageName: $packageName,
                ),
                status: $tuple[1],
                conflictWith: $tuple[2] ?? null,
                description: $tuple[3] ?? null,
            );
        }

        return new DonorInspection(
            donor: new VendorConfig(
                packageName: $packageName,
                packageRoot: Path::create('/vendor/' . $packageName),
                source: $source,
            ),
            trustSource: $trust,
            skills: $skillInspections,
        );
    }
}
