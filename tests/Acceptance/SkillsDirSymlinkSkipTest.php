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
 * End-to-end proof that `skills:update` never follows a symlink or NTFS
 * junction living inside a donor skill directory.
 *
 * The sandbox's committed `dir` fixture donor
 * (`tests/Sandbox/project/local-skills/`) is reused. The test plants a
 * directory link inside the `dir-hello` skill that points at a tree
 * outside the donor, runs the real sync, and asserts the linked content
 * never lands in the target while the run still exits 0. The link is
 * created honestly at runtime (junction on Windows, symlink elsewhere)
 * and removed in teardown; if the platform refuses link creation the
 * test skips cleanly rather than fake the fixture.
 */
#[Test]
final class SkillsDirSymlinkSkipTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';
    private const LINK_PATH = Info::PROJECT_DIR . '/local-skills/dir-hello/leaked-link';

    private string $secretDir;

    #[BeforeTest]
    public function setUp(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        Filesystem::removeRecursive(self::LINK_PATH);
        $this->secretDir = \sys_get_temp_dir() . '/llm-skills-leak-' . \bin2hex(\random_bytes(6));
    }

    #[AfterTest]
    public function cleanUp(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        Filesystem::removeRecursive(self::LINK_PATH);
        Filesystem::removeRecursive($this->secretDir);
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function updateSkipsALinkedDirectoryInsideADonorSkill(): void
    {
        \mkdir($this->secretDir, 0o777, true);
        \file_put_contents($this->secretDir . '/secret.txt', 'must not leak');

        if (!Filesystem::makeDirLink($this->secretDir, self::LINK_PATH)) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        $process = $this->runUpdate();

        // The link is skipped for security, but the sync itself still succeeds.
        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());

        // The regular skill content arrives...
        Assert::true(
            \is_file(self::TARGET_DIR . '/dir-hello/SKILL.md'),
            'the donor skill must still be copied. stderr: ' . $process->getErrorOutput(),
        );
        // ...but nothing was dragged through the link.
        Assert::false(
            \is_dir(self::TARGET_DIR . '/dir-hello/leaked-link'),
            'the linked directory must not be traversed into the target',
        );
        Assert::false(
            \is_file(self::TARGET_DIR . '/dir-hello/leaked-link/secret.txt'),
            'content behind the link must never leak into the target',
        );
    }

    private function runUpdate(): Process
    {
        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:update',
            timeout: 60,
            mustSucceed: false,
        );
    }
}
