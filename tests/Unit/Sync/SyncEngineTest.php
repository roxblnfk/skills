<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Sync;

use Internal\Path;
use LLM\Skills\Discovery\Skill;
use LLM\Skills\Sync\SyncEngine;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
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
