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
 * Acceptance coverage for a `dir` donor that holds the sync target and keeps
 * its own skill outside the conventional skill containers, as a project
 * registered with `{"from": "dir", "path": "."}` does.
 *
 * The committed fixture `local-target-host-fallback/` ships `my-skills/foo/`;
 * the target is its `.agents/skills`. A copy installed there must not stop the
 * donor's own `foo` from being found.
 */
#[Test]
final class SkillsDonorOwnSkillsBesideTargetTest
{
    private const HOST_DIR = Info::PROJECT_DIR . '/local-target-host-fallback';
    private const TARGET_ROOT = self::HOST_DIR . '/.agents';
    private const TARGET_DIR = self::TARGET_ROOT . '/skills';

    #[BeforeTest]
    public static function reset(): void
    {
        Filesystem::removeRecursive(self::TARGET_ROOT);
    }

    #[AfterTest]
    public static function cleanup(): void
    {
        Filesystem::removeRecursive(self::TARGET_ROOT);
    }

    #[WithSkillsJson([
        'target' => 'local-target-host-fallback/.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-target-host-fallback'],
        ],
    ])]
    public function aSecondSyncAndACleanSyncKeepTheDonorsOwnSkill(): void
    {
        $first = $this->run('skills:update');
        Assert::same($first->getExitCode(), 0, $this->diagnostics($first));
        Assert::true(\is_file(self::TARGET_DIR . '/foo/SKILL.md'), $this->diagnostics($first));

        $second = $this->run('skills:update');
        Assert::same($second->getExitCode(), 0, $this->diagnostics($second));
        Assert::true(
            \str_contains($second->getOutput(), '[copy] foo'),
            'the second sync must still copy foo from its donor. ' . $this->diagnostics($second),
        );

        // An unscoped clean wipes the target, so foo must be put back.
        $clean = $this->run('skills:update --clean');
        Assert::same($clean->getExitCode(), 0, $this->diagnostics($clean));
        Assert::true(
            \str_contains($clean->getOutput(), '[copy] foo'),
            'foo is the donor\'s own skill, not a stale copy. ' . $this->diagnostics($clean),
        );
        Assert::true(\is_file(self::TARGET_DIR . '/foo/SKILL.md'), $this->diagnostics($clean));
    }

    #[WithSkillsJson([
        'target' => 'local-target-host-fallback/.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-target-host-fallback'],
        ],
    ])]
    public function aCleanSyncRemovesACopyNoDonorShipsAnyMore(): void
    {
        \mkdir(self::TARGET_DIR . '/stale-copy', 0o777, true);
        \file_put_contents(self::TARGET_DIR . '/stale-copy/SKILL.md', "---\nname: stale-copy\n---\nstale\n");

        $process = $this->run('skills:update --clean');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::false(
            \is_dir(self::TARGET_DIR . '/stale-copy'),
            'a copy in the target is not a source of the donor holding it. ' . $this->diagnostics($process),
        );
        Assert::true(\is_file(self::TARGET_DIR . '/foo/SKILL.md'), $this->diagnostics($process));
    }

    #[WithSkillsJson([
        'target' => 'local-target-host-fallback/.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-target-host-fallback', 'skills' => ['foo']],
        ],
    ])]
    public function aCleanSyncOfAnAllowlistedDonorIsNotRefusedOnceItsSkillIsInstalled(): void
    {
        $first = $this->run('skills:update');
        Assert::same($first->getExitCode(), 0, $this->diagnostics($first));

        $clean = $this->run('skills:update --clean');

        Assert::same($clean->getExitCode(), 0, $this->diagnostics($clean));
        Assert::false(\str_contains($clean->getErrorOutput(), '--clean refused'), $this->diagnostics($clean));
        Assert::true(\is_file(self::TARGET_DIR . '/foo/SKILL.md'), $this->diagnostics($clean));
    }

    #[WithSkillsJson([
        'target' => 'local-target-host-fallback/.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-target-host-fallback', 'skills' => ['copied']],
        ],
    ])]
    public function aRefusedCleanSaysAnInstalledCopyDoesNotCountForTheAllowlist(): void
    {
        \mkdir(self::TARGET_DIR . '/copied', 0o777, true);
        \file_put_contents(self::TARGET_DIR . '/copied/SKILL.md', "---\nname: copied\n---\nan installed copy\n");

        $process = $this->run('skills:update --clean');

        Assert::notSame($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(\str_contains($process->getErrorOutput(), '--clean refused'), $this->diagnostics($process));
        Assert::true(
            \str_contains(
                $process->getOutput(),
                '[failed] skill "copied" declared in the skill allowlist but not found in the donor; '
                . 'its copy installed in the target',
            ),
            $this->diagnostics($process),
        );
        Assert::true(\is_file(self::TARGET_DIR . '/copied/SKILL.md'), 'a refused clean deletes nothing');
    }

    #[WithSkillsJson([
        'target' => 'local-target-host-fallback/.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-target-host-fallback'],
        ],
    ])]
    public function showListsTheDonorsOwnSkillOnceItIsInstalled(): void
    {
        $sync = $this->run('skills:update');
        Assert::same($sync->getExitCode(), 0, $this->diagnostics($sync));

        $process = $this->run('skills:show');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        $rows = \array_values(\array_filter(
            \preg_split('/\R/', $process->getOutput()) ?: [],
            static fn(string $line): bool => \preg_match('/^\s+\S+\s+foo\b/', $line) === 1,
        ));
        Assert::same(\count($rows), 1, 'foo must be listed once. ' . $this->diagnostics($process));
        Assert::true(\str_starts_with(\ltrim($rows[0]), 'OK '), 'foo must be in sync. Got: ' . $rows[0]);
    }

    #[WithSkillsJson([
        'target' => 'local-target-host-fallback/.agents/skills',
    ])]
    public function addAcceptsTheDirectoryBesideAnInstalledCopy(): void
    {
        \mkdir(self::TARGET_DIR . '/copied', 0o777, true);
        \file_put_contents(self::TARGET_DIR . '/copied/SKILL.md', "---\nname: copied\n---\nan installed copy\n");

        $process = $this->run('skills:add ./local-target-host-fallback');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \str_contains((string) \file_get_contents(Info::PROJECT_DIR . '/skills.json'), 'local-target-host-fallback"'),
            'the directory must be registered. ' . $this->diagnostics($process),
        );
        Assert::true(\is_file(self::TARGET_DIR . '/foo/SKILL.md'), $this->diagnostics($process));
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
