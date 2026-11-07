<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Composer\WithSkillsJson;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * End-to-end proof that `skills:update` never writes through a symlink or
 * NTFS junction the user made inside the target directory.
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

    private string $checkoutDir;

    #[BeforeTest]
    public function setUp(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        $this->checkoutDir = \sys_get_temp_dir() . '/llm-skills-checkout-' . \bin2hex(\random_bytes(6));
    }

    #[AfterTest]
    public function cleanUp(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        Filesystem::removeRecursive($this->checkoutDir);
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function updateLeavesALinkedSkillDirectoryAndItsTargetAlone(): void
    {
        \mkdir($this->checkoutDir, 0o777, true);
        \file_put_contents($this->checkoutDir . '/SKILL.md', 'my own version');
        \mkdir(self::TARGET_DIR, 0o777, true);
        if (!Filesystem::makeDirLink($this->checkoutDir, self::TARGET_DIR . '/dir-hello')) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        $process = ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:update',
            timeout: 60,
            mustSucceed: false,
        );

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
            \preg_match('/\[skip\] \S*dir-hello: a symlink\/junction inside the target/', $process->getErrorOutput()) === 1,
            'the skipped link must be reported at the default verbosity. stderr: ' . $process->getErrorOutput(),
        );
        Assert::false(
            \str_contains($process->getOutput() . $process->getErrorOutput(), '[copy] dir-hello'),
            'the linked skill must not be listed as copied. stdout: ' . $process->getOutput(),
        );
    }
}
