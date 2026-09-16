<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Composer\WithSkillsJson;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance tests for `composer skills:update --clean`.
 *
 * The sandbox donors are described in {@see SkillsSyncTest}. What matters
 * here is the difference between the default merge and the wipe: a plain
 * `skills:update` never deletes, so anything that a donor stopped shipping —
 * a file, a whole skill, an entire package — lingers in the target until a
 * `--clean` run removes it.
 */
#[Test]
final class SkillsCleanTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';

    #[BeforeTest]
    public function clearTargetDir(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
    }

    public function unscopedCleanRemovesASkillNoDonorShipsAnyMore(): void
    {
        $this->runSync();
        $this->plantSkill('gone-from-donor');

        $process = $this->runSync('--clean');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::false(
            \is_dir(self::TARGET_DIR . '/gone-from-donor'),
            'an orphaned skill directory must not survive an unscoped --clean',
        );
        Assert::true(\is_file(self::TARGET_DIR . '/greeting/SKILL.md'), 'donor skills are written back');
    }

    public function cleanRemovesAFileTheDonorNoLongerShips(): void
    {
        $this->runSync();
        \file_put_contents(self::TARGET_DIR . '/greeting/leftover.md', 'dropped by the donor');

        $this->runSync('--clean');

        Assert::false(
            \is_file(self::TARGET_DIR . '/greeting/leftover.md'),
            'a file inside a synced skill must not survive --clean, unlike the default merge',
        );
        Assert::true(\is_file(self::TARGET_DIR . '/greeting/SKILL.md'));
    }

    public function plainSyncKeepsTheFileThatCleanWouldRemove(): void
    {
        // The contrast that makes the flag worth having: without it the merge
        // is non-destructive, so the leftover stays.
        $this->runSync();
        \file_put_contents(self::TARGET_DIR . '/greeting/leftover.md', 'dropped by the donor');

        $this->runSync();

        Assert::true(\is_file(self::TARGET_DIR . '/greeting/leftover.md'));
    }

    public function scopedCleanLeavesSkillsOutsideTheFilterAlone(): void
    {
        $this->runSync();
        $this->plantSkill('gone-from-donor');

        $process = $this->runSync('acme/skills-basic', '--clean');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \is_dir(self::TARGET_DIR . '/gone-from-donor'),
            'a filtered run must not delete what it cannot write back',
        );
        Assert::true(
            \is_file(self::TARGET_DIR . '/refactor/SKILL.md'),
            'another donor’s skills must survive a filtered --clean',
        );
        Assert::true(\is_file(self::TARGET_DIR . '/greeting/SKILL.md'));
    }

    public function scopedCleanStillWipesTheSkillsItReinstalls(): void
    {
        $this->runSync();
        \file_put_contents(self::TARGET_DIR . '/greeting/leftover.md', 'dropped by the donor');

        $this->runSync('acme/skills-basic', '--clean');

        Assert::false(\is_file(self::TARGET_DIR . '/greeting/leftover.md'));
        Assert::true(\is_file(self::TARGET_DIR . '/greeting/SKILL.md'));
    }

    public function dryRunAnnouncesTheRemovalWithoutPerformingIt(): void
    {
        $this->runSync();
        $this->plantSkill('gone-from-donor');

        $process = $this->runSync('--clean', '--dry-run');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \str_contains($process->getOutput(), '[would remove] gone-from-donor'),
            'dry-run must name what --clean would delete. Got: ' . $process->getOutput(),
        );
        Assert::true(
            \is_dir(self::TARGET_DIR . '/gone-from-donor'),
            'dry-run must not delete anything',
        );
    }

    public function removalIsReportedPerSkill(): void
    {
        $this->runSync();

        $process = $this->runSync('--clean');

        Assert::true(
            \str_contains($process->getOutput(), '[remove] greeting'),
            'each deleted skill must be named in the output. Got: ' . $process->getOutput(),
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './no-such-dir'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function cleanIsRefusedWhenASourceCouldNotBeResolved(): void
    {
        // A donor that failed to resolve contributes no skills, so a wipe would
        // trade a working install for whatever the run could still reach.
        $this->runSync();
        $before = $this->listTargetEntries();
        Assert::true($before !== [], 'the plain sync must have installed something to protect');
        // A sentinel inside a skill, because comparing directory names alone
        // would also pass if the refusal deleted each skill and recopied it —
        // losing exactly the local files the refusal exists to protect.
        \file_put_contents(self::TARGET_DIR . '/greeting/sentinel.md', 'local only');

        $process = $this->runSync('--clean');

        Assert::same($process->getExitCode(), 1, 'a refused --clean must not report success');
        Assert::true(
            \str_contains($process->getErrorOutput(), '--clean refused'),
            'the refusal must say why. Got: ' . $process->getErrorOutput(),
        );
        Assert::same(
            $this->listTargetEntries(),
            $before,
            'a refused --clean must leave the target exactly as it was',
        );
        Assert::same(
            \file_get_contents(self::TARGET_DIR . '/greeting/sentinel.md'),
            'local only',
            'a refused --clean must not touch the contents of an installed skill either',
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './vanished-skills'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function cleanIsRefusedWhenADonorSourceDirectoryIsMissing(): void
    {
        // `vanished-skills` resolves as a donor but declares a source directory
        // that is not there, so the enumerator drops it. Its skills are absent
        // from this run, and an unscoped wipe would have nothing to put back.
        $this->runSync();
        \file_put_contents(self::TARGET_DIR . '/greeting/sentinel.md', 'local only');

        $process = $this->runSync('--clean');

        Assert::same($process->getExitCode(), 1, 'stdout: ' . $process->getOutput());
        Assert::true(
            \str_contains($process->getErrorOutput(), '--clean refused')
            && \str_contains($process->getErrorOutput(), 'acme/dir-vanished'),
            'the refusal must name the donor that dropped out. Got: ' . $process->getErrorOutput(),
        );
        Assert::same(\file_get_contents(self::TARGET_DIR . '/greeting/sentinel.md'), 'local only');
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './vanished-skills'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function aScopedCleanProceedsDespiteADonorThatDroppedOut(): void
    {
        // The narrow run deletes only what it reinstalls, so the broken donor
        // costs it nothing — refusing here would leave no way to clean at all.
        $this->runSync();
        \file_put_contents(self::TARGET_DIR . '/greeting/leftover.md', 'dropped by the donor');

        $process = $this->runSync('acme/skills-basic', '--clean');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::false(\is_file(self::TARGET_DIR . '/greeting/leftover.md'));
        Assert::true(\is_file(self::TARGET_DIR . '/greeting/SKILL.md'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Write a skill directory into the target that no donor ships, standing in
     * for one left behind by a donor that was removed from the project.
     *
     * @param non-empty-string $name
     */
    private function plantSkill(string $name): void
    {
        $dir = self::TARGET_DIR . '/' . $name;
        \mkdir($dir, 0o777, true);
        \file_put_contents($dir . '/SKILL.md', "---\nname: " . $name . "\n---\n");
    }

    private function runSync(string ...$args): Process
    {
        $command = 'skills:update';
        if ($args !== []) {
            $command .= ' ' . \implode(' ', $args);
        }

        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            $command,
            timeout: 60,
            mustSucceed: false,
        );
    }

    /**
     * @return list<string> sorted list of immediate entries under {@see self::TARGET_DIR}
     */
    private function listTargetEntries(): array
    {
        if (!\is_dir(self::TARGET_DIR)) {
            return [];
        }

        $entries = \array_values(\array_diff(\scandir(self::TARGET_DIR) ?: [], ['.', '..']));
        \sort($entries);

        return $entries;
    }
}
