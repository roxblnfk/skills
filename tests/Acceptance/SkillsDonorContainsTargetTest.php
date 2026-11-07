<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Composer\WithSkillsJson;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance coverage for a `dir` donor whose directory holds the sync target:
 * the skills installed there are copies from other donors, not its own.
 *
 * The committed fixture `local-target-host/` ships no skills; each test points
 * the target at `local-target-host/.agents/skills` and seeds it with an
 * installed copy of `local-skills/dir-hello`.
 */
#[Test]
final class SkillsDonorContainsTargetTest
{
    private const HOST_DIR = Info::PROJECT_DIR . '/local-target-host';
    private const TARGET_ROOT = self::HOST_DIR . '/.agents';
    private const TARGET_DIR = self::TARGET_ROOT . '/skills';

    #[BeforeTest]
    public static function reset(): void
    {
        Filesystem::removeRecursive(self::TARGET_ROOT);
        \mkdir(self::TARGET_DIR . '/dir-hello', 0o777, true);
        \copy(Info::PROJECT_DIR . '/local-skills/dir-hello/SKILL.md', self::TARGET_DIR . '/dir-hello/SKILL.md');
    }

    #[AfterTest]
    public static function cleanup(): void
    {
        Filesystem::removeRecursive(self::TARGET_ROOT);
    }

    #[WithSkillsJson([
        'target' => 'local-target-host/.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
            ['from' => 'dir', 'path' => './local-target-host'],
        ],
    ])]
    public function syncDoesNotCountTheInstalledCopiesAsSkillsOfTheDonorHoldingTheTarget(): void
    {
        $process = $this->run('skills:update');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::false(
            \str_contains($process->getErrorOutput(), '[conflict]'),
            'the installed copy must not clash with its own source. ' . $this->diagnostics($process),
        );
        Assert::true(\is_file(self::TARGET_DIR . '/dir-extra/SKILL.md'), $this->diagnostics($process));
    }

    #[WithSkillsJson([
        'target' => 'local-target-host/.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
            ['from' => 'dir', 'path' => './local-target-host'],
        ],
    ])]
    public function cleanSyncRemovesACopyNoDonorShipsAnyMore(): void
    {
        \mkdir(self::TARGET_DIR . '/stale-copy', 0o777, true);
        \file_put_contents(self::TARGET_DIR . '/stale-copy/SKILL.md', "---\nname: stale-copy\n---\nstale\n");

        $process = $this->run('skills:update --clean');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::false(
            \is_dir(self::TARGET_DIR . '/stale-copy'),
            'a copy in the target is not a source of the donor holding it. ' . $this->diagnostics($process),
        );
        Assert::true(\is_file(self::TARGET_DIR . '/dir-hello/SKILL.md'), $this->diagnostics($process));
    }

    #[WithSkillsJson([
        'target' => 'local-target-host/.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
            ['from' => 'dir', 'path' => './local-target-host'],
        ],
    ])]
    public function showListsAnInstalledCopyUnderItsDonorOnly(): void
    {
        $process = $this->run('skills:show');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        $rows = \array_values(\array_filter(
            \preg_split('/\R/', $process->getOutput()) ?: [],
            static fn(string $line): bool => \preg_match('/^\s+\S+\s+dir-hello\b/', $line) === 1,
        ));
        Assert::same(\count($rows), 1, 'dir-hello must be listed once. ' . $this->diagnostics($process));
        Assert::true(\str_starts_with(\ltrim($rows[0]), 'OK '), 'dir-hello must be in sync. Got: ' . $rows[0]);
    }

    #[WithSkillsJson([
        'target' => 'local-target-host/.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
        ],
    ])]
    public function addRefusesADirectoryWhoseOnlySkillsAreTheInstalledCopies(): void
    {
        $process = $this->run('skills:add ./local-target-host');

        Assert::notSame($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getErrorOutput(), 'its only skills are the copies installed in the target'),
            $this->diagnostics($process),
        );
        Assert::false(
            \str_contains((string) \file_get_contents(Info::PROJECT_DIR . '/skills.json'), 'local-target-host"'),
            'the refused directory must not be registered',
        );
    }

    /**
     * @param non-empty-string $command
     */
    private function run(string $command): Process
    {
        return ComposerRunner::run(Path::create(Info::PROJECT_DIR), $command, timeout: 60, mustSucceed: false);
    }

    private function diagnostics(Process $process): string
    {
        return 'stdout: ' . $process->getOutput() . "\nstderr: " . $process->getErrorOutput();
    }
}
