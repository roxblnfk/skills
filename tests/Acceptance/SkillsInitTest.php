<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Composer\WithSandboxExtras;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance tests for `composer skills:init` inside the sandbox
 * project — the Composer-attached half of the command, where a real
 * donor tree exists and the follow-up sync has something to copy.
 *
 * `init` writes `skills.json` into the shared sandbox and migrates the
 * inline `extra.skills` block out of its `composer.json`. The attribute
 * restores `composer.json`; the lifecycle hooks here remove the
 * generated `skills.json` and the target directory, so the sandbox is
 * back at its baseline for whatever runs next.
 */
#[Test]
final class SkillsInitTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';
    private const CONFIG_FILE = Info::PROJECT_DIR . '/skills.json';

    #[BeforeTest]
    #[AfterTest]
    public function resetSandbox(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        if (\is_file(self::CONFIG_FILE)) {
            \unlink(self::CONFIG_FILE);
        }
    }

    #[WithSandboxExtras(['trusted' => ['acme/skills-basic']])]
    public function initSyncsTheTrustedDonorsOnceTheConfigIsWritten(): void
    {
        // The command's promise: run it and the project has skills, not
        // just a config file plus a second command to remember.
        $process = $this->runInit('--quick');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::CONFIG_FILE), 'skills.json must be written');
        Assert::true(
            \is_file(self::TARGET_DIR . '/greeting/SKILL.md'),
            'init must sync after writing the config. stderr: ' . $process->getErrorOutput(),
        );
    }

    #[WithSandboxExtras(['trusted' => ['acme/skills-basic']])]
    public function initTurnsCleanOnForAnEmptyTarget(): void
    {
        $process = $this->runInit('--quick --no-sync');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::same($this->readConfig()['clean'] ?? null, true, 'nothing installed yet, nothing to lose');
    }

    #[WithSandboxExtras(['trusted' => ['acme/skills-basic']])]
    public function initKeepsTheMergeForATargetThatAlreadyHoldsSkills(): void
    {
        \mkdir(self::TARGET_DIR . '/mine', 0o777, true);
        \file_put_contents(self::TARGET_DIR . '/mine/SKILL.md', "---\nname: mine\n---\n");

        $process = $this->runInit('--quick');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::false(\array_key_exists('clean', $this->readConfig()));
        Assert::true(
            \is_file(self::TARGET_DIR . '/mine/SKILL.md'),
            'the follow-up sync must not delete a skill that was already there',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfig(): array
    {
        /** @var array<string, mixed> */
        return \json_decode((string) \file_get_contents(self::CONFIG_FILE), true, flags: \JSON_THROW_ON_ERROR);
    }

    #[WithSandboxExtras(['trusted' => ['acme/skills-basic']])]
    public function noSyncFlagWritesTheConfigAndCopiesNothing(): void
    {
        $process = $this->runInit('--quick --no-sync');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::CONFIG_FILE), 'the config is still written');
        Assert::false(
            \is_dir(self::TARGET_DIR),
            '--no-sync must leave the filesystem untouched beyond the config',
        );
    }

    /**
     * @param non-empty-string $args
     */
    private function runInit(string $args): Process
    {
        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:init ' . $args,
            timeout: 60,
            mustSucceed: false,
        );
    }
}
