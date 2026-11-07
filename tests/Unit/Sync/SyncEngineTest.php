<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Sync;

use Internal\Path;
use LLM\Skills\Discovery\InstalledSkill;
use LLM\Skills\Discovery\Skill;
use LLM\Skills\Filesystem\LinkGuard;
use LLM\Skills\Sync\SyncEngine;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests the `SyncEngine` in isolation: given already-enumerated
 * {@see Skill}s, it must detect conflicts and copy. Filesystem
 * enumeration is exercised in {@see \LLM\Skills\Tests\Unit\Discovery\SkillEnumeratorTest}.
 *
 * Each test runs against a fresh temporary directory tree:
 *
 *   <tmp>/
 *     skills/<skill-name>/...    # the "source" half — what donors ship
 *     target/                    # the "destination" half
 *
 * The {@see BeforeTest} hook builds an empty `<tmp>`; the {@see AfterTest}
 * hook tears it down so we never carry state between tests.
 */
#[Test]
final class SyncEngineTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function syncsSingleSkill(): void
    {
        $skill = $this->makeSkill('acme/skills-basic', 'greeting', ['SKILL.md' => '# Greeting']);

        $report = (new SyncEngine())->sync([$skill], $this->target());

        Assert::true($report->isSuccess());
        Assert::same(\count($report->copied), 1);
        Assert::true(\is_file($this->targetPath('greeting/SKILL.md')));
    }

    public function syncsMultipleSkillsFromOneDonor(): void
    {
        $a = $this->makeSkill('acme/skills-basic', 'greeting', ['SKILL.md' => '# Greeting']);
        $b = $this->makeSkill('acme/skills-basic', 'code-review', ['SKILL.md' => '# Review']);

        $report = (new SyncEngine())->sync([$a, $b], $this->target());

        Assert::true($report->isSuccess());
        Assert::same(\count($report->copied), 2);
        Assert::true(\is_file($this->targetPath('greeting/SKILL.md')));
        Assert::true(\is_file($this->targetPath('code-review/SKILL.md')));
    }

    public function syncsSkillsFromMultipleDonors(): void
    {
        $a = $this->makeSkill('acme/basic', 'greeting', ['SKILL.md' => '# A']);
        $b = $this->makeSkill('acme/pro', 'refactor', ['SKILL.md' => '# B']);

        $report = (new SyncEngine())->sync([$a, $b], $this->target());

        Assert::true($report->isSuccess());
        Assert::true(\is_file($this->targetPath('greeting/SKILL.md')));
        Assert::true(\is_file($this->targetPath('refactor/SKILL.md')));
    }

    public function neverCopiesTheTargetIntoASkillWhoseSourceContainsIt(): void
    {
        $donorRoot = $this->tmp . '/project';
        \mkdir($donorRoot . '/.agents/skills/other', 0o777, true);
        \file_put_contents($donorRoot . '/SKILL.md', '# Root');
        \file_put_contents($donorRoot . '/.agents/notes.md', 'notes');
        \file_put_contents($donorRoot . '/.agents/skills/other/SKILL.md', '# Other');
        $skill = new Skill(
            name: 'root',
            canonicalName: 'root',
            sourceDir: Path::create($donorRoot),
            packageName: 'acme/root',
        );

        $report = (new SyncEngine())->sync([$skill], Path::create($donorRoot . '/.agents/skills'));

        Assert::true($report->isSuccess());
        Assert::same($report->truncatedDirs, []);
        Assert::true(\is_file($donorRoot . '/.agents/skills/root/SKILL.md'));
        Assert::true(\is_file($donorRoot . '/.agents/skills/root/.agents/notes.md'));
        Assert::false(\file_exists($donorRoot . '/.agents/skills/root/.agents/skills'));
    }

    public function neverCopiesTheTargetIntoItselfWhenTheSourceIsTheTarget(): void
    {
        $target = $this->tmp . '/target';
        \mkdir($target . '/other', 0o777, true);
        \file_put_contents($target . '/SKILL.md', '# Root');
        $skill = new Skill(
            name: 'root',
            canonicalName: 'root',
            sourceDir: Path::create($target),
            packageName: 'acme/root',
        );

        $report = (new SyncEngine())->sync([$skill], $this->target());

        Assert::true($report->isSuccess());
        Assert::same($report->truncatedDirs, []);
        Assert::same($report->copied, []);
        Assert::same($report->targetAsSource, ['root']);
        Assert::false(\file_exists($target . '/root/root'));
        Assert::false(\file_exists($target . '/root/other'));
    }

    public function namesDifferingOnlyByCaseConflict(): void
    {
        $a = $this->makeSkill('acme/one', 'Helper', ['SKILL.md' => '# A']);
        $b = $this->makeSkill('acme/two', 'helper', ['SKILL.md' => '# B']);

        $report = (new SyncEngine())->sync([$a, $b], $this->target());

        Assert::false($report->isSuccess());
        Assert::count($report->conflicts, 1);
        Assert::same($report->conflicts[0]->name, 'Helper');
        Assert::same($report->conflicts[0]->packages, ['acme/one', 'acme/two']);
        Assert::false(\is_dir($this->targetPath('helper')));
    }

    public function keepsASkillWhoseSourceIsItsOwnInstalledDirectory(): void
    {
        $installed = $this->installTarget('my-skill', ['SKILL.md' => '# Mine', 'notes/a.md' => 'notes']);
        $skill = new Skill(
            name: 'my-skill',
            canonicalName: 'my-skill',
            sourceDir: $installed->dir,
            packageName: 'project/my-skill',
        );

        $report = (new SyncEngine())->sync([$skill], $this->target(), purge: [$installed]);

        Assert::true($report->isSuccess());
        Assert::same($report->removed, []);
        Assert::same($report->copied, [$skill]);
        Assert::same($report->keptSources, ['my-skill']);
        Assert::same(\file_get_contents($this->targetPath('my-skill/SKILL.md')), '# Mine');
        Assert::same(\file_get_contents($this->targetPath('my-skill/notes/a.md')), 'notes');
    }

    public function dryRunDoesNotPlanToPurgeASkillSource(): void
    {
        $installed = $this->installTarget('my-skill', ['SKILL.md' => '# Mine']);
        $skill = new Skill(
            name: 'renamed',
            canonicalName: 'renamed',
            sourceDir: Path::create((string) $installed->dir . '/'),
            packageName: 'project/my-skill',
        );

        $report = (new SyncEngine())->sync([$skill], $this->target(), dryRun: true, purge: [$installed]);

        Assert::same($report->removed, []);
        Assert::same($report->keptSources, ['my-skill']);
    }

    public function copiesNestedFilesRecursively(): void
    {
        $skill = $this->makeSkill('acme/pro', 'refactor', [
            'SKILL.md' => '# Refactor',
            'templates/suggestion.md' => 'template body',
            'examples/before.md' => 'before',
        ]);

        (new SyncEngine())->sync([$skill], $this->target());

        Assert::true(\is_file($this->targetPath('refactor/SKILL.md')));
        Assert::true(\is_file($this->targetPath('refactor/templates/suggestion.md')));
        Assert::true(\is_file($this->targetPath('refactor/examples/before.md')));
    }

    public function preservesFileContentByteForByte(): void
    {
        $body = "line one\nline two\n# heading\n";
        $skill = $this->makeSkill('acme/basic', 'greeting', ['SKILL.md' => $body]);

        (new SyncEngine())->sync([$skill], $this->target());

        Assert::same(\file_get_contents($this->targetPath('greeting/SKILL.md')), $body);
    }

    public function isIdempotentOnSecondRun(): void
    {
        $skill = $this->makeSkill('acme/basic', 'greeting', ['SKILL.md' => '# Greeting']);
        $engine = new SyncEngine();

        $engine->sync([$skill], $this->target());
        $report = $engine->sync([$skill], $this->target());

        Assert::true($report->isSuccess());
        Assert::true(\is_file($this->targetPath('greeting/SKILL.md')));
    }

    public function overwritesVendorOwnedFilesWithCurrentContent(): void
    {
        $skill = $this->makeSkill('acme/basic', 'greeting', ['SKILL.md' => 'new content']);

        // Pre-populate the target with an older version of the same file.
        \mkdir($this->targetPath('greeting'), 0o777, true);
        \file_put_contents($this->targetPath('greeting/SKILL.md'), 'old content');

        (new SyncEngine())->sync([$skill], $this->target());

        Assert::same(\file_get_contents($this->targetPath('greeting/SKILL.md')), 'new content');
    }

    public function preservesUserFilesNotPresentInVendor(): void
    {
        $skill = $this->makeSkill('acme/basic', 'greeting', ['SKILL.md' => '# Greeting']);

        // User-added file inside the same skill dir.
        \mkdir($this->targetPath('greeting'), 0o777, true);
        \file_put_contents($this->targetPath('greeting/local-notes.md'), 'mine');

        (new SyncEngine())->sync([$skill], $this->target());

        Assert::true(\is_file($this->targetPath('greeting/local-notes.md')));
        Assert::same(\file_get_contents($this->targetPath('greeting/local-notes.md')), 'mine');
    }

    public function reportsConflictWhenTwoSkillsShareAName(): void
    {
        $a = $this->makeSkill('acme/basic', 'greeting', ['SKILL.md' => '# A']);
        $b = $this->makeSkill('acme/pro', 'greeting', ['SKILL.md' => '# B']);

        $report = (new SyncEngine())->sync([$a, $b], $this->target());

        Assert::true($report->hasConflicts());
        Assert::same(\count($report->conflicts), 1);
        Assert::same($report->conflicts[0]->name, 'greeting');
        Assert::same($report->conflicts[0]->packages, ['acme/basic', 'acme/pro']);
        Assert::same($report->copied, []);
        Assert::false(\is_file($this->targetPath('greeting/SKILL.md')));
    }

    public function reportsEveryConflictNotJustTheFirst(): void
    {
        $skills = [
            $this->makeSkill('acme/basic', 'greeting', ['SKILL.md' => '# A1']),
            $this->makeSkill('acme/basic', 'review', ['SKILL.md' => '# A2']),
            $this->makeSkill('acme/pro', 'greeting', ['SKILL.md' => '# B1']),
            $this->makeSkill('acme/pro', 'review', ['SKILL.md' => '# B2']),
        ];

        $report = (new SyncEngine())->sync($skills, $this->target());

        Assert::same(\count($report->conflicts), 2);
        $names = \array_map(static fn($c) => $c->name, $report->conflicts);
        \sort($names);
        Assert::same($names, ['greeting', 'review']);
    }

    public function createsTargetDirectoryIfMissing(): void
    {
        $skill = $this->makeSkill('acme/basic', 'greeting', ['SKILL.md' => '# Greeting']);
        $target = Path::create($this->tmp . '/fresh-target');

        (new SyncEngine())->sync([$skill], $target);

        Assert::true(\is_dir($this->tmp . '/fresh-target'));
        Assert::true(\is_file($this->tmp . '/fresh-target/greeting/SKILL.md'));
    }

    public function dryRunReportsSkillsThatWouldBeCopiedWithoutWritingThem(): void
    {
        $a = $this->makeSkill('acme/basic', 'greeting', ['SKILL.md' => '# Greeting']);
        $b = $this->makeSkill('acme/basic', 'code-review', ['SKILL.md' => '# Review']);

        $report = (new SyncEngine())->sync([$a, $b], $this->target(), dryRun: true);

        Assert::true($report->isSuccess());
        Assert::same(\count($report->copied), 2, 'report lists what would have been copied');
        Assert::false(\is_dir($this->tmp . '/target'));
        Assert::false(\is_file($this->targetPath('greeting/SKILL.md')));
        Assert::false(\is_file($this->targetPath('code-review/SKILL.md')));
    }

    public function dryRunStillDetectsConflictsAndDoesNotCreateTarget(): void
    {
        $a = $this->makeSkill('acme/basic', 'greeting', ['SKILL.md' => '# A']);
        $b = $this->makeSkill('acme/pro', 'greeting', ['SKILL.md' => '# B']);

        $report = (new SyncEngine())->sync([$a, $b], $this->target(), dryRun: true);

        Assert::true($report->hasConflicts());
        Assert::same(\count($report->conflicts), 1);
        Assert::false(\is_dir($this->tmp . '/target'));
    }

    public function dryRunLeavesExistingTargetFilesUntouched(): void
    {
        $skill = $this->makeSkill('acme/basic', 'greeting', ['SKILL.md' => 'donor version']);
        \mkdir($this->targetPath('greeting'), 0o777, true);
        \file_put_contents($this->targetPath('greeting/SKILL.md'), 'pre-existing content');

        (new SyncEngine())->sync([$skill], $this->target(), dryRun: true);

        Assert::same(\file_get_contents($this->targetPath('greeting/SKILL.md')), 'pre-existing content');
    }

    // ── purge phase ─────────────────────────────────────────────────────────

    public function purgeDropsFilesTheDonorNoLongerShips(): void
    {
        $skill = $this->makeSkill('acme/basic', 'greeting', ['SKILL.md' => 'donor version']);
        $installed = $this->installTarget('greeting', [
            'SKILL.md' => 'old version',
            'dropped.md' => 'no longer shipped',
        ]);

        $report = (new SyncEngine())->sync([$skill], $this->target(), purge: [$installed]);

        Assert::same($report->removed, ['greeting']);
        Assert::same($report->removalFailures, []);
        Assert::same(\file_get_contents($this->targetPath('greeting/SKILL.md')), 'donor version');
        Assert::false(
            \is_file($this->targetPath('greeting/dropped.md')),
            'a file the donor dropped must not survive the purge',
        );
    }

    public function purgeRemovesASkillNoDonorWritesBack(): void
    {
        $orphan = $this->installTarget('orphan', ['SKILL.md' => '# Orphan']);

        $report = (new SyncEngine())->sync([], $this->target(), purge: [$orphan]);

        Assert::same($report->removed, ['orphan']);
        Assert::false(\is_dir($this->targetPath('orphan')));
    }

    public function conflictAbortLeavesTheTargetIntactDespiteAPurgeList(): void
    {
        // Validation runs first for exactly this case: the collision means
        // nothing can be written, so nothing may be deleted either.
        $a = $this->makeSkill('acme/basic', 'greeting', ['SKILL.md' => '# A']);
        $b = $this->makeSkill('acme/pro', 'greeting', ['SKILL.md' => '# B']);
        $installed = $this->installTarget('greeting', ['SKILL.md' => 'pre-existing']);

        $report = (new SyncEngine())->sync([$a, $b], $this->target(), purge: [$installed]);

        Assert::true($report->hasConflicts());
        Assert::same($report->removed, []);
        Assert::same(\file_get_contents($this->targetPath('greeting/SKILL.md')), 'pre-existing');
    }

    public function dryRunReportsThePurgeWithoutDeletingAnything(): void
    {
        $skill = $this->makeSkill('acme/basic', 'greeting', ['SKILL.md' => 'donor version']);
        $installed = $this->installTarget('greeting', ['SKILL.md' => 'pre-existing']);

        $report = (new SyncEngine())->sync(
            [$skill],
            $this->target(),
            dryRun: true,
            purge: [$installed],
        );

        Assert::same($report->removed, ['greeting']);
        Assert::same(\file_get_contents($this->targetPath('greeting/SKILL.md')), 'pre-existing');
    }

    public function emptyPurgeListKeepsTheMergeNonDestructive(): void
    {
        $skill = $this->makeSkill('acme/basic', 'greeting', ['SKILL.md' => 'donor version']);
        $this->installTarget('greeting', ['SKILL.md' => 'old', 'notes.md' => 'user notes']);

        $report = (new SyncEngine())->sync([$skill], $this->target());

        Assert::same($report->removed, []);
        Assert::same(\file_get_contents($this->targetPath('greeting/SKILL.md')), 'donor version');
        Assert::true(\is_file($this->targetPath('greeting/notes.md')));
    }

    public function skipsSymlinkedDirectoriesAndFilesAndReportsThem(): void
    {
        // Security: a link inside a donor could point at a large or sensitive
        // tree beyond the skill. The copy must land the regular files, skip
        // both link kinds, and report each skip so it is never silent.
        $skill = $this->makeSkill('acme/pro', 'refactor', ['SKILL.md' => '# Refactor']);
        $skillDir = (string) $skill->sourceDir;

        // A tree outside the skill that the links try to drag in.
        $outside = $this->tmp . '/outside';
        \mkdir($outside, 0o777, true);
        \file_put_contents($outside . '/secret.txt', 'secret');

        $dirLink = $skillDir . \DIRECTORY_SEPARATOR . 'leak';
        $fileLink = $skillDir . \DIRECTORY_SEPARATOR . 'link.txt';
        $dirLinkMade = Filesystem::makeDirLink($outside, $dirLink);
        $fileLinkMade = Filesystem::makeFileLink($outside . '/secret.txt', $fileLink);

        if (!$dirLinkMade && !$fileLinkMade) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        $report = (new SyncEngine())->sync([$skill], $this->target());

        Assert::true($report->isSuccess());
        Assert::true(\is_file($this->targetPath('refactor/SKILL.md')), 'regular files are still copied');

        if ($dirLinkMade) {
            Assert::false(
                \is_dir($this->targetPath('refactor/leak')),
                'a linked subdirectory must not be traversed into the target',
            );
            Assert::false(\is_file($this->targetPath('refactor/leak/secret.txt')));
            Assert::true(
                \in_array($dirLink, $report->skippedLinks, true),
                'the skipped directory link must be reported',
            );
        }

        if ($fileLinkMade) {
            Assert::false(
                \is_file($this->targetPath('refactor/link.txt')),
                'a symlinked file must not be copied',
            );
            Assert::true(
                \in_array($fileLink, $report->skippedLinks, true),
                'the skipped file link must be reported',
            );
        }
    }

    public function terminatesOnASymlinkCycleAndReportsTheSkip(): void
    {
        // A link that loops back to the skill root: following it would recurse
        // forever. The copy must break the loop, skip the link, and finish.
        $skill = $this->makeSkill('acme/pro', 'refactor', ['SKILL.md' => '# Refactor']);
        $skillDir = (string) $skill->sourceDir;
        $loop = $skillDir . \DIRECTORY_SEPARATOR . 'loop';

        if (!Filesystem::makeDirLink($skillDir, $loop)) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        $report = (new SyncEngine())->sync([$skill], $this->target());

        Assert::true($report->isSuccess());
        Assert::true(\is_file($this->targetPath('refactor/SKILL.md')));
        Assert::false(\is_dir($this->targetPath('refactor/loop')));
        Assert::same($report->skippedLinks, [$loop]);
    }

    public function neverWritesThroughASkillDirectoryLinkedInsideTheTarget(): void
    {
        $skill = $this->makeSkill('acme/pdf', 'pdf', ['SKILL.md' => 'donor version', 'extra.md' => 'donor extra']);
        $checkout = $this->tmp . '/checkout';
        \mkdir($checkout, 0o777, true);
        \file_put_contents($checkout . '/SKILL.md', 'my version');
        \mkdir($this->tmp . '/target', 0o777, true);
        $link = $this->target()->join('pdf');
        if (!Filesystem::makeDirLink($checkout, (string) $link)) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        $report = (new SyncEngine())->sync([$skill], $this->target());

        Assert::true($report->isSuccess());
        Assert::same(\file_get_contents($checkout . '/SKILL.md'), 'my version');
        Assert::false(\file_exists($checkout . '/extra.md'));
        Assert::same($report->copied, []);
        Assert::same($report->linkedDestinations, [(string) $link]);
    }

    public function neverWritesThroughALinkDeeperInsideAnInstalledSkill(): void
    {
        $skill = $this->makeSkill('acme/pdf', 'pdf', ['SKILL.md' => 'donor version', 'scripts/run.sh' => 'donor']);
        $elsewhere = $this->tmp . '/elsewhere';
        \mkdir($elsewhere, 0o777, true);
        \mkdir($this->targetPath('pdf'), 0o777, true);
        $link = (string) $this->target()->join('pdf', 'scripts');
        if (!Filesystem::makeDirLink($elsewhere, $link)) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        $report = (new SyncEngine())->sync([$skill], $this->target());

        Assert::true($report->isSuccess());
        Assert::same(\file_get_contents($this->targetPath('pdf/SKILL.md')), 'donor version');
        Assert::false(\file_exists($elsewhere . '/run.sh'));
        Assert::same(\count($report->copied), 1);
        Assert::same($report->linkedDestinations, [$link]);
    }

    public function neverWritesThroughAFileLinkInsideTheTarget(): void
    {
        $skill = $this->makeSkill('acme/pdf', 'pdf', ['SKILL.md' => 'donor version', 'notes.md' => 'donor notes']);
        $outsideFile = $this->tmp . '/outside.md';
        \file_put_contents($outsideFile, 'mine');
        \mkdir($this->targetPath('pdf'), 0o777, true);
        $link = (string) $this->target()->join('pdf', 'notes.md');
        if (!Filesystem::makeFileLink($outsideFile, $link)) {
            throw new SkipTest('platform refuses file symlink creation');
        }

        $report = (new SyncEngine())->sync([$skill], $this->target());

        Assert::true($report->isSuccess());
        Assert::same(\file_get_contents($this->targetPath('pdf/SKILL.md')), 'donor version');
        Assert::same(\file_get_contents($outsideFile), 'mine');
        Assert::same($report->linkedDestinations, [$link]);
    }

    public function dryRunReportsALinkedSkillDirectoryUnlessThePurgeStripsIt(): void
    {
        $kept = $this->makeSkill('acme/pdf', 'pdf', ['SKILL.md' => 'donor version']);
        $purged = $this->makeSkill('acme/pdf', 'docx', ['SKILL.md' => 'donor version']);
        $checkout = $this->tmp . '/checkout';
        \mkdir($checkout, 0o777, true);
        \file_put_contents($checkout . '/SKILL.md', 'my version');
        \mkdir($this->tmp . '/target', 0o777, true);
        $keptLink = $this->target()->join('pdf');
        $purgedLink = $this->target()->join('docx');
        if (!Filesystem::makeDirLink($checkout, (string) $keptLink)
            || !Filesystem::makeDirLink($checkout, (string) $purgedLink)
        ) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        $report = (new SyncEngine())->sync(
            [$kept, $purged],
            $this->target(),
            dryRun: true,
            purge: [new InstalledSkill(name: 'docx', dir: $purgedLink)],
        );

        Assert::same($report->linkedDestinations, [(string) $keptLink]);
        Assert::same(\array_map(static fn(Skill $s): string => $s->name, $report->copied), ['docx']);
        Assert::same(\file_get_contents($checkout . '/SKILL.md'), 'my version');
    }

    public function countsASkillDirectoryLinkedToItsOwnSourceAsInPlace(): void
    {
        $skill = $this->makeSkill('acme/pdf', 'pdf', ['SKILL.md' => 'donor version']);
        \mkdir($this->tmp . '/target', 0o777, true);
        if (!Filesystem::makeDirLink((string) $skill->sourceDir, $this->targetPath('pdf'))) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        $dryRun = (new SyncEngine())->sync([$skill], $this->target(), dryRun: true);
        $report = (new SyncEngine())->sync([$skill], $this->target());

        Assert::same($dryRun->copied, [$skill]);
        Assert::same($dryRun->linkedDestinations, []);
        Assert::same($report->copied, [$skill]);
        Assert::same($report->linkedDestinations, []);
        Assert::same(\file_get_contents($this->targetPath('pdf/SKILL.md')), 'donor version');
    }

    public function cleanStripsALinkedSkillDirectoryAndInstallsAFreshCopy(): void
    {
        $skill = $this->makeSkill('acme/pdf', 'pdf', ['SKILL.md' => 'donor version']);
        $checkout = $this->tmp . '/checkout';
        \mkdir($checkout, 0o777, true);
        \file_put_contents($checkout . '/SKILL.md', 'my version');
        \file_put_contents($checkout . '/mine.md', 'mine');
        \mkdir($this->tmp . '/target', 0o777, true);
        $link = $this->target()->join('pdf');
        if (!Filesystem::makeDirLink($checkout, (string) $link)) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        $report = (new SyncEngine())->sync(
            [$skill],
            $this->target(),
            purge: [new InstalledSkill(name: 'pdf', dir: $link)],
        );

        Assert::true($report->isSuccess());
        Assert::same($report->removed, ['pdf']);
        Assert::same($report->copied, [$skill]);
        Assert::same($report->linkedDestinations, []);
        Assert::same(\file_get_contents($checkout . '/SKILL.md'), 'my version');
        Assert::same(\file_get_contents($checkout . '/mine.md'), 'mine');
        Assert::false(LinkGuard::isLink((string) $link));
        Assert::same(\file_get_contents($this->targetPath('pdf/SKILL.md')), 'donor version');
        Assert::false(\file_exists($this->targetPath('pdf/mine.md')));
    }

    public function replacesAHardLinkedFileWithoutTruncatingItsOtherName(): void
    {
        $skill = $this->makeSkill('acme/pdf', 'pdf', ['SKILL.md' => 'donor version']);
        $outsideFile = $this->tmp . '/outside.md';
        \file_put_contents($outsideFile, 'mine');
        \mkdir($this->targetPath('pdf'), 0o777, true);
        if (!@\link($outsideFile, $this->targetPath('pdf/SKILL.md'))) {
            throw new SkipTest('platform refuses hard link creation');
        }

        $report = (new SyncEngine())->sync([$skill], $this->target());

        Assert::true($report->isSuccess());
        Assert::same(\file_get_contents($outsideFile), 'mine');
        Assert::same(\file_get_contents($this->targetPath('pdf/SKILL.md')), 'donor version');
        Assert::same($report->linkedDestinations, []);
    }

    public function aDirectoryWhereTheDonorShipsAFileIsNotReportedAsALink(): void
    {
        // A POSIX directory has a link count of two or more, as a hard-linked file does.
        $skill = $this->makeSkill('acme/pdf', 'pdf', ['SKILL.md' => 'donor version', 'notes' => 'donor notes']);
        \mkdir($this->targetPath('pdf/notes'), 0o777, true);
        \file_put_contents($this->targetPath('pdf/notes/mine.md'), 'mine');

        $report = (new SyncEngine())->sync([$skill], $this->target());

        Assert::same($report->linkedDestinations, []);
        Assert::same(\file_get_contents($this->targetPath('pdf/SKILL.md')), 'donor version');
        Assert::same(\file_get_contents($this->targetPath('pdf/notes/mine.md')), 'mine');
    }

    public function stopsAtTheDepthCapAndReportsTheTruncationPoint(): void
    {
        // A tree deeper than the copy backstop must still copy what it can
        // and surface where it stopped, so a truncated sync is never silent.
        $skill = $this->makeSkill('acme/deep', 'deep', ['SKILL.md' => '# Deep']);
        $skillDir = (string) $skill->sourceDir;

        // A chain of nested directories well past the cap, with a marker
        // file at the very bottom the copy can never reach.
        $depth = 40; // > SyncEngine::MAX_COPY_DEPTH (32)
        $chain = $skillDir;
        for ($i = 0; $i < $depth; $i++) {
            $chain .= \DIRECTORY_SEPARATOR . 'd';
        }
        \mkdir($chain, 0o777, true);
        \file_put_contents($chain . \DIRECTORY_SEPARATOR . 'bottom.txt', 'too deep');

        $report = (new SyncEngine())->sync([$skill], $this->target());

        Assert::true($report->isSuccess());
        // Shallow content still lands.
        Assert::true(\is_file($this->targetPath('deep/SKILL.md')));
        // The bottom marker never made it past the cap.
        Assert::false(\is_file($this->targetPath('deep/' . \str_repeat('d/', $depth) . 'bottom.txt')));
        // The truncation point is reported exactly once and names a source
        // directory inside the deep chain.
        Assert::same(\count($report->truncatedDirs), 1);
        Assert::true(\str_contains($report->truncatedDirs[0], $skillDir));
        Assert::same($report->skippedLinks, []);
    }

    /**
     * Lay out a skill's files inside `<tmp>/skills/<package>/<skillName>/` and
     * return a Skill pointing at the directory. Different packages get
     * different subtrees so two skills can coexist with the same name on
     * disk.
     *
     * @param non-empty-string $packageName
     * @param non-empty-string $skillName
     * @param array<non-empty-string, string> $files map of relative path → file contents
     */
    private function makeSkill(string $packageName, string $skillName, array $files): Skill
    {
        $skillDir = $this->tmp . '/skills/' . \rawurlencode($packageName) . '/' . $skillName;
        \mkdir($skillDir, 0o777, true);

        foreach ($files as $rel => $contents) {
            $full = $skillDir . '/' . $rel;
            $dir = \dirname($full);
            if (!\is_dir($dir)) {
                \mkdir($dir, 0o777, true);
            }
            \file_put_contents($full, $contents);
        }

        return new Skill(
            name: $skillName,
            canonicalName: $skillName,
            sourceDir: Path::create($skillDir),
            packageName: $packageName,
        );
    }

    /**
     * Lay out a skill directly under `<tmp>/target/` as if a previous sync had
     * written it, and return the {@see InstalledSkill} a scanner would produce
     * for it.
     *
     * @param non-empty-string $skillName
     * @param array<non-empty-string, string> $files map of relative path → file contents
     */
    private function installTarget(string $skillName, array $files): InstalledSkill
    {
        $dir = $this->tmp . '/target/' . $skillName;
        \mkdir($dir, 0o777, true);

        foreach ($files as $rel => $contents) {
            $full = $dir . '/' . $rel;
            $parent = \dirname($full);
            if (!\is_dir($parent)) {
                \mkdir($parent, 0o777, true);
            }
            \file_put_contents($full, $contents);
        }

        return new InstalledSkill(name: $skillName, dir: Path::create($dir));
    }

    private function target(): Path
    {
        return Path::create($this->tmp . '/target');
    }

    /**
     * @param non-empty-string $relative
     */
    private function targetPath(string $relative): string
    {
        return $this->tmp . '/target/' . $relative;
    }
}
