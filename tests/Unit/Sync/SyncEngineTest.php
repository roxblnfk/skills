<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Sync;

use Internal\Path;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Sync\SyncEngine;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Each test runs against a fresh temporary directory tree:
 *
 *   <tmp>/
 *     vendor/<package>/<source>/<skill>/...
 *     target/
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

    public function syncsSingleSkillFromOneVendor(): void
    {
        $donor = $this->makeDonor('acme/skills-basic', 'src', [
            'greeting/SKILL.md' => '# Greeting',
        ]);

        $report = (new SyncEngine())->sync([$donor], $this->target());

        Assert::true($report->isSuccess());
        Assert::same(\count($report->copied), 1);
        Assert::true(\is_file($this->targetPath('greeting/SKILL.md')));
    }

    public function syncsMultipleSkillsFromOneVendor(): void
    {
        $donor = $this->makeDonor('acme/skills-basic', '.claude/skills', [
            'greeting/SKILL.md' => '# Greeting',
            'code-review/SKILL.md' => '# Review',
        ]);

        $report = (new SyncEngine())->sync([$donor], $this->target());

        Assert::true($report->isSuccess());
        Assert::same(\count($report->copied), 2);
        Assert::true(\is_file($this->targetPath('greeting/SKILL.md')));
        Assert::true(\is_file($this->targetPath('code-review/SKILL.md')));
    }

    public function syncsSkillsFromMultipleVendors(): void
    {
        $a = $this->makeDonor('acme/basic', 'src', ['greeting/SKILL.md' => '# A']);
        $b = $this->makeDonor('acme/pro', 'res', ['refactor/SKILL.md' => '# B']);

        $report = (new SyncEngine())->sync([$a, $b], $this->target());

        Assert::true($report->isSuccess());
        Assert::true(\is_file($this->targetPath('greeting/SKILL.md')));
        Assert::true(\is_file($this->targetPath('refactor/SKILL.md')));
    }

    public function copiesNestedFilesRecursively(): void
    {
        $donor = $this->makeDonor('acme/pro', 'src', [
            'refactor/SKILL.md' => '# Refactor',
            'refactor/templates/suggestion.md' => 'template body',
            'refactor/examples/before.md' => 'before',
        ]);

        $report = (new SyncEngine())->sync([$donor], $this->target());

        Assert::true($report->isSuccess());
        Assert::true(\is_file($this->targetPath('refactor/SKILL.md')));
        Assert::true(\is_file($this->targetPath('refactor/templates/suggestion.md')));
        Assert::true(\is_file($this->targetPath('refactor/examples/before.md')));
    }

    public function preservesFileContentByteForByte(): void
    {
        $body = "line one\nline two\n# heading\n";
        $donor = $this->makeDonor('acme/basic', 'src', ['greeting/SKILL.md' => $body]);

        (new SyncEngine())->sync([$donor], $this->target());

        Assert::same(\file_get_contents($this->targetPath('greeting/SKILL.md')), $body);
    }

    public function isIdempotentOnSecondRun(): void
    {
        $donor = $this->makeDonor('acme/basic', 'src', ['greeting/SKILL.md' => '# Greeting']);
        $engine = new SyncEngine();

        $engine->sync([$donor], $this->target());
        $report = $engine->sync([$donor], $this->target());

        Assert::true($report->isSuccess());
        Assert::true(\is_file($this->targetPath('greeting/SKILL.md')));
    }

    public function overwritesVendorOwnedFilesWithCurrentContent(): void
    {
        $donor = $this->makeDonor('acme/basic', 'src', ['greeting/SKILL.md' => 'new content']);

        // Pre-populate the target with an older version of the same file.
        \mkdir($this->targetPath('greeting'), 0o777, true);
        \file_put_contents($this->targetPath('greeting/SKILL.md'), 'old content');

        (new SyncEngine())->sync([$donor], $this->target());

        Assert::same(\file_get_contents($this->targetPath('greeting/SKILL.md')), 'new content');
    }

    public function preservesUserFilesNotPresentInVendor(): void
    {
        $donor = $this->makeDonor('acme/basic', 'src', ['greeting/SKILL.md' => '# Greeting']);

        // User-added file inside the same skill dir.
        \mkdir($this->targetPath('greeting'), 0o777, true);
        \file_put_contents($this->targetPath('greeting/local-notes.md'), 'mine');

        (new SyncEngine())->sync([$donor], $this->target());

        Assert::true(\is_file($this->targetPath('greeting/local-notes.md')));
        Assert::same(\file_get_contents($this->targetPath('greeting/local-notes.md')), 'mine');
    }

    public function reportsConflictWhenTwoVendorsClaimSameSkillName(): void
    {
        $a = $this->makeDonor('acme/basic', 'src', ['greeting/SKILL.md' => '# A']);
        $b = $this->makeDonor('acme/pro', 'src', ['greeting/SKILL.md' => '# B']);

        $report = (new SyncEngine())->sync([$a, $b], $this->target());

        Assert::true($report->hasConflicts());
        Assert::same(\count($report->conflicts), 1);
        Assert::same($report->conflicts[0]->name, 'greeting');
        Assert::same($report->conflicts[0]->packages, ['acme/basic', 'acme/pro']);
        Assert::same($report->copied, []);
        // Nothing written.
        Assert::false(\is_file($this->targetPath('greeting/SKILL.md')));
    }

    public function continuesPastDonorWithMissingSource(): void
    {
        // First donor has no source dir — the engine must still process the
        // second one rather than aborting (or breaking out of the discovery
        // loop) after the warning.
        $broken = new VendorConfig(
            'acme/broken',
            Path::create($this->tmp . '/vendor/acme/broken'),
            'src',
        );
        \mkdir($this->tmp . '/vendor/acme/broken', 0o777, true);

        $good = $this->makeDonor('acme/good', 'src', ['refactor/SKILL.md' => '# OK']);

        $report = (new SyncEngine())->sync([$broken, $good], $this->target());

        Assert::true($report->isSuccess());
        Assert::same(\count($report->warnings), 1);
        Assert::same(\count($report->copied), 1);
        Assert::same($report->copied[0]->packageName, 'acme/good');
        Assert::true(\is_file($this->targetPath('refactor/SKILL.md')));
    }

    public function reportsEveryConflictNotJustTheFirst(): void
    {
        $a = $this->makeDonor('acme/basic', 'src', [
            'greeting/SKILL.md' => '# A1',
            'review/SKILL.md' => '# A2',
        ]);
        $b = $this->makeDonor('acme/pro', 'src', [
            'greeting/SKILL.md' => '# B1',
            'review/SKILL.md' => '# B2',
        ]);

        $report = (new SyncEngine())->sync([$a, $b], $this->target());

        Assert::same(\count($report->conflicts), 2);
        $names = \array_map(static fn($c) => $c->name, $report->conflicts);
        \sort($names);
        Assert::same($names, ['greeting', 'review']);
    }

    public function warnsWhenSourceDirectoryMissing(): void
    {
        // Donor declares "src" but we never create that directory.
        $packageRoot = $this->tmp . '/vendor/acme/empty';
        \mkdir($packageRoot, 0o777, true);
        $donor = new VendorConfig('acme/empty', Path::create($packageRoot), 'src');

        $report = (new SyncEngine())->sync([$donor], $this->target());

        Assert::true($report->isSuccess());
        Assert::same($report->copied, []);
        Assert::same(\count($report->warnings), 1);
    }

    public function ignoresLooseFilesAtSourceRoot(): void
    {
        // A README sitting next to skill directories is not itself a skill.
        $donor = $this->makeDonor('acme/basic', 'src', [
            'README.md' => 'top-level readme',
            'greeting/SKILL.md' => '# Greeting',
        ]);

        $report = (new SyncEngine())->sync([$donor], $this->target());

        Assert::true($report->isSuccess());
        Assert::same(\count($report->copied), 1);
        Assert::same($report->copied[0]->name, 'greeting');
        Assert::false(\is_file($this->targetPath('README.md')));
    }

    public function createsTargetDirectoryIfMissing(): void
    {
        $donor = $this->makeDonor('acme/basic', 'src', ['greeting/SKILL.md' => '# Greeting']);
        // Note: target/ does not exist yet — engine must create it.
        $target = Path::create($this->tmp . '/fresh-target');

        $report = (new SyncEngine())->sync([$donor], $target);

        Assert::true($report->isSuccess());
        Assert::true(\is_dir($this->tmp . '/fresh-target'));
        Assert::true(\is_file($this->tmp . '/fresh-target/greeting/SKILL.md'));
    }

    public function dryRunReportsSkillsThatWouldBeCopiedWithoutWritingThem(): void
    {
        $donor = $this->makeDonor('acme/basic', 'src', [
            'greeting/SKILL.md' => '# Greeting',
            'code-review/SKILL.md' => '# Review',
        ]);

        $report = (new SyncEngine())->sync([$donor], $this->target(), dryRun: true);

        Assert::true($report->isSuccess());
        Assert::same(\count($report->copied), 2, 'report lists what would have been copied');
        // Target directory is not created and no files written.
        Assert::false(\is_dir($this->tmp . '/target'));
        Assert::false(\is_file($this->targetPath('greeting/SKILL.md')));
        Assert::false(\is_file($this->targetPath('code-review/SKILL.md')));
    }

    public function dryRunStillDetectsConflictsAndDoesNotCreateTarget(): void
    {
        // Two donors claim the same `greeting` skill name — must be reported
        // even in dry-run, and no filesystem state must change.
        $a = $this->makeDonor('acme/basic', 'src', ['greeting/SKILL.md' => '# A']);
        $b = $this->makeDonor('acme/pro', 'src', ['greeting/SKILL.md' => '# B']);

        $report = (new SyncEngine())->sync([$a, $b], $this->target(), dryRun: true);

        Assert::true($report->hasConflicts());
        Assert::same(\count($report->conflicts), 1);
        Assert::false(\is_dir($this->tmp . '/target'));
    }

    public function dryRunLeavesExistingTargetFilesUntouched(): void
    {
        // Pre-existing file in target must NOT be overwritten by dry-run even
        // when the donor ships a different version.
        $donor = $this->makeDonor('acme/basic', 'src', ['greeting/SKILL.md' => 'donor version']);
        \mkdir($this->targetPath('greeting'), 0o777, true);
        \file_put_contents($this->targetPath('greeting/SKILL.md'), 'pre-existing content');

        (new SyncEngine())->sync([$donor], $this->target(), dryRun: true);

        Assert::same(\file_get_contents($this->targetPath('greeting/SKILL.md')), 'pre-existing content');
    }

    /**
     * @param non-empty-string                $packageName
     * @param non-empty-string                $sourceDir   directory inside the fake package (e.g. "src", ".claude/skills")
     * @param array<non-empty-string, string> $files       map of "<skill>/<path>" → contents
     */
    private function makeDonor(string $packageName, string $sourceDir, array $files): VendorConfig
    {
        $packageRoot = $this->tmp . '/vendor/' . $packageName;
        $source = $packageRoot . '/' . $sourceDir;
        \mkdir($source, 0o777, true);

        foreach ($files as $rel => $contents) {
            $full = $source . '/' . $rel;
            $dir = \dirname($full);
            if (!\is_dir($dir)) {
                \mkdir($dir, 0o777, true);
            }
            \file_put_contents($full, $contents);
        }

        return new VendorConfig($packageName, Path::create($packageRoot), $sourceDir);
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
