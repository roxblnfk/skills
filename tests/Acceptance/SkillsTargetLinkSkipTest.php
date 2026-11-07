<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Composer\WithSkillsJson;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * End-to-end proof that `skills:update` never writes through a symlink or
 * NTFS junction the user made inside the target directory, and that
 * `skills:show` reports such a skill the way the sync treats it.
 *
 * The installed `dir-hello` skill of the sandbox's `dir` fixture donor
 * (`tests/Sandbox/project/local-skills/`) is replaced by a link to a
 * directory outside the project, standing in for a local checkout of the
 * user's own skill. The link is created at runtime (junction on Windows,
 * symlink elsewhere) and the test skips when the platform refuses both.
 */
#[Test]
final class SkillsTargetLinkSkipTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';
    private const DIR_SOURCE = [
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ];

    private string $checkoutDir;

    #[BeforeTest]
    public function setUp(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        $this->checkoutDir = \sys_get_temp_dir() . '/llm-skills-checkout-' . \bin2hex(\random_bytes(6));
        \mkdir($this->checkoutDir, 0o777, true);
    }

    #[AfterTest]
    public function cleanUp(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        Filesystem::removeRecursive($this->checkoutDir);
    }

    #[WithSkillsJson(self::DIR_SOURCE)]
    public function updateLeavesALinkedSkillDirectoryAndItsTargetAlone(): void
    {
        $this->linkDirHelloToTheCheckout();

        $process = $this->composer('skills:update');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::same(
            \file_get_contents($this->checkoutDir . '/SKILL.md'),
            'my own version',
            'the donor must not overwrite the directory the link points at',
        );
        Assert::true(
            \is_file(self::TARGET_DIR . '/dir-extra/SKILL.md'),
            'the other skills are still copied. stderr: ' . $process->getErrorOutput(),
        );
        Assert::true(
            \preg_match('/\[skip\] \S*dir-hello: a link inside the target/', $process->getErrorOutput()) === 1,
            'the skipped link must be reported at the default verbosity. stderr: ' . $process->getErrorOutput(),
        );
        Assert::false(
            \str_contains($process->getOutput(), '[copy] dir-hello'),
            'the linked skill must not be listed as copied. stdout: ' . $process->getOutput(),
        );
    }

    #[WithSkillsJson(self::DIR_SOURCE)]
    public function dryRunReportsALinkedSkillDirectoryWithoutTouchingIt(): void
    {
        $this->linkDirHelloToTheCheckout();

        $process = $this->composer('skills:update --dry-run');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \preg_match('/\[skip\] \S*dir-hello: a link inside the target/', $process->getErrorOutput()) === 1,
            'the dry run must name the link the real run skips. stderr: ' . $process->getErrorOutput(),
        );
        Assert::false(
            \str_contains($process->getOutput(), '[would copy] dir-hello'),
            'the linked skill must not be listed as one to copy. stdout: ' . $process->getOutput(),
        );
        Assert::true(\str_contains($process->getOutput(), '[would copy] dir-extra'), $process->getOutput());
        Assert::same(\file_get_contents($this->checkoutDir . '/SKILL.md'), 'my own version');
    }

    #[WithSkillsJson(self::DIR_SOURCE)]
    public function cleanRemovesOnlyTheLinkAndInstallsAFreshCopy(): void
    {
        $this->linkDirHelloToTheCheckout();
        \file_put_contents($this->checkoutDir . '/notes.md', 'mine');

        $process = $this->composer('skills:update --clean');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::same(\file_get_contents($this->checkoutDir . '/SKILL.md'), 'my own version');
        Assert::same(\file_get_contents($this->checkoutDir . '/notes.md'), 'mine');
        Assert::same(
            \file_get_contents(self::TARGET_DIR . '/dir-hello/SKILL.md'),
            \file_get_contents(Info::PROJECT_DIR . '/local-skills/dir-hello/SKILL.md'),
            'a fresh copy replaces the link. stderr: ' . $process->getErrorOutput(),
        );
        Assert::false(\is_file(self::TARGET_DIR . '/dir-hello/notes.md'), 'the copy is not written through a link');
        Assert::true(\str_contains($process->getOutput(), '[copy] dir-hello'), $process->getOutput());
        Assert::false(\str_contains($process->getErrorOutput(), 'a link inside the target'), $process->getErrorOutput());
    }

    #[WithSkillsJson(self::DIR_SOURCE)]
    public function showListsALinkedSkillDirectoryAsSkipped(): void
    {
        $this->linkDirHelloToTheCheckout();

        $process = $this->composer('skills:show');
        $out = $process->getOutput();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \preg_match('/target-is-link\s+\S+\s+skill "dir-hello": its directory in the target is a symlink\/junction/', $out) === 1,
            'show must list the linked skill as skipped, as the sync does. stdout: ' . $out,
        );
        Assert::false(\str_contains($out, ' MOD'), 'a skill the sync leaves alone is not drift. stdout: ' . $out);
    }

    #[WithSkillsJson(self::DIR_SOURCE)]
    public function aSkillDirectoryLinkedToItsOwnSourceCountsAsInPlace(): void
    {
        $source = Info::PROJECT_DIR . '/local-skills/dir-hello';
        $original = \file_get_contents($source . '/SKILL.md');
        \mkdir(self::TARGET_DIR, 0o777, true);
        if (!Filesystem::makeDirLink($source, self::TARGET_DIR . '/dir-hello')) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        $update = $this->composer('skills:update');
        $show = $this->composer('skills:show');

        Assert::same($update->getExitCode(), 0, 'stderr: ' . $update->getErrorOutput());
        Assert::true(\str_contains($update->getOutput(), '[copy] dir-hello'), $update->getOutput());
        Assert::false(
            \str_contains($update->getErrorOutput(), 'a link inside the target'),
            'a link to the skill\'s own source is not a skipped link. stderr: ' . $update->getErrorOutput(),
        );
        Assert::same(\file_get_contents($source . '/SKILL.md'), $original);
        Assert::false(\str_contains($show->getOutput(), 'target-is-link'), $show->getOutput());
    }

    public function showStillFlagsAConflictOnALinkedSkillDirectory(): void
    {
        \mkdir(self::TARGET_DIR, 0o777, true);
        \file_put_contents($this->checkoutDir . '/SKILL.md', 'my own version');
        if (!Filesystem::makeDirLink($this->checkoutDir, self::TARGET_DIR . '/greeting')) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        $update = $this->composer('skills:update --trust=clash/skills-conflict');
        $show = $this->composer('skills:show --trust=clash/skills-conflict');
        $out = $show->getOutput();

        Assert::notSame($update->getExitCode(), 0, 'the conflict aborts the sync. stderr: ' . $update->getErrorOutput());
        Assert::same($show->getExitCode(), 0, 'stderr: ' . $show->getErrorOutput());
        Assert::true(
            \preg_match('/ !! .*greeting/', $out) === 1,
            'show must flag the conflict that aborts the sync. stdout: ' . $out,
        );
        Assert::false(\str_contains($out, 'target-is-link'), $out);
    }

    public function showIgnoresALinkDeeperInsideAnInstalledSkill(): void
    {
        $this->composer('skills:update');
        $templates = self::TARGET_DIR . '/refactor/templates';
        Filesystem::removeRecursive($templates);
        if (!Filesystem::makeDirLink($this->checkoutDir, $templates)) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        $process = $this->composer('skills:show');
        $out = $process->getOutput();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\str_contains($out, 'refactor'), $out);
        Assert::false(
            \str_contains($out, ' MOD'),
            'a linked directory the sync never writes through is not drift. stdout: ' . $out,
        );
    }

    private function linkDirHelloToTheCheckout(): void
    {
        \file_put_contents($this->checkoutDir . '/SKILL.md', 'my own version');
        \mkdir(self::TARGET_DIR, 0o777, true);
        if (!Filesystem::makeDirLink($this->checkoutDir, self::TARGET_DIR . '/dir-hello')) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }
    }

    /**
     * @param non-empty-string $command
     */
    private function composer(string $command): Process
    {
        return ComposerRunner::run(Path::create(Info::PROJECT_DIR), $command, timeout: 60, mustSucceed: false);
    }
}
