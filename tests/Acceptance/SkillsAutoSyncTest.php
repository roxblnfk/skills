<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Composer\WithSandboxExtras;
use LLM\Skills\Tests\Testo\Filesystem;
use LLM\Skills\Tests\Testo\SandboxStateGuard;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance tests for the `extra.skills.auto-sync` opt-in.
 *
 * When the project sets `auto-sync: true`, the plugin must subscribe to
 * Composer's `post-install-cmd` / `post-update-cmd` events and run
 * `skills:update` itself — removing the need for a hand-written
 * `scripts.post-*-cmd` entry. These tests dispatch the events directly via
 * `composer run-script <event>`, which is dramatically faster than a real
 * `composer install` while exercising the exact same event-dispatch path.
 */
#[Test]
final class SkillsAutoSyncTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';

    #[BeforeTest]
    public function clearTargetDir(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        SandboxStateGuard::snapshot();
    }

    #[AfterTest]
    public function restoreSandbox(): void
    {
        SandboxStateGuard::restore();
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
        'auto-sync' => true,
    ])]
    public function autoSyncRunsAfterPostInstallCmdEvent(): void
    {
        // Plugin subscribes to ScriptEvents::POST_INSTALL_CMD; firing that
        // event must materialise the skills in the configured target.
        $process = $this->runScript('post-install-cmd');

        Assert::same(
            $process->getExitCode(),
            0,
            'run-script post-install-cmd must exit 0 with auto-sync on. stderr: '
            . $process->getErrorOutput(),
        );
        Assert::true(
            \is_file(self::TARGET_DIR . '/greeting/SKILL.md'),
            'auto-sync must copy trusted donor skills on post-install-cmd. stderr: '
            . $process->getErrorOutput(),
        );
        Assert::true(\is_file(self::TARGET_DIR . '/refactor/SKILL.md'));
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
        'auto-sync' => true,
    ])]
    public function autoSyncRunsAfterPostUpdateCmdEvent(): void
    {
        // Same handler is wired to POST_UPDATE_CMD — exercising it via the
        // update event proves we did not accidentally subscribe only to
        // install.
        $process = $this->runScript('post-update-cmd');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::TARGET_DIR . '/greeting/SKILL.md'));
    }

    public function autoSyncDefaultsToOffAndDoesNotWriteAnything(): void
    {
        // Sandbox's default `extra.skills` has no `auto-sync` key. Firing
        // the install event must be a no-op for our plugin — the user
        // hasn't opted in, so the filesystem stays untouched.
        $process = $this->runScript('post-install-cmd');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::false(
            \is_dir(self::TARGET_DIR),
            'auto-sync must stay off by default; nothing should appear under target',
        );
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic'],
        'auto-sync' => 'yes',
    ])]
    public function malformedAutoSyncValueIsReportedAndSkipsSync(): void
    {
        // Mapper rejects non-boolean values; the hook surfaces the error
        // through IO but never throws, so the surrounding event dispatch
        // still exits cleanly and the filesystem is left alone.
        $process = $this->runScript('post-install-cmd');

        Assert::true(
            \str_contains($process->getErrorOutput(), 'extra.skills.auto-sync'),
            'stderr must explain the malformed value. Got: ' . $process->getErrorOutput(),
        );
        Assert::false(
            \is_dir(self::TARGET_DIR),
            'sync must not run when the config is malformed',
        );
    }

    private function runScript(string $event): Process
    {
        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'run-script ' . $event,
            timeout: 60,
            mustSucceed: false,
        );
    }
}
