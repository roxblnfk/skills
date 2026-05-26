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
 * Acceptance coverage for the spec §6.2 `--from=<id>` filter on
 * `skills:update`.
 *
 * Sanity tests against the existing sandbox donor packages. We don't
 * have a remote provider hooked up here (Phase 7 ships the HTTP
 * fixture), so the coverage focuses on:
 *
 * - `--from=composer` keeps Composer donors visible (no behaviour
 *   change vs. unfiltered sync).
 * - `--from=github` filters Composer donors out and produces the
 *   neutral "matched no donors" notice.
 */
#[Test]
final class SkillsFromFilterTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';
    private const SKILLS_JSON = Info::PROJECT_DIR . '/skills.json';

    #[BeforeTest]
    public function clearSyncTarget(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
    }

    #[AfterTest]
    public function removeSkillsJson(): void
    {
        if (\is_file(self::SKILLS_JSON)) {
            @\unlink(self::SKILLS_JSON);
        }
    }

    #[WithSkillsJson([
        'trusted' => ['acme/skills-basic'],
    ])]
    public function fromComposerKeepsLocalDonorsVisible(): void
    {
        // Sanity: with --from=composer, the existing sandbox donor
        // acme/skills-basic still syncs as it would without the flag.
        $process = $this->runSync('--from=composer');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \is_file(self::TARGET_DIR . '/greeting/SKILL.md'),
            'composer donors must sync under --from=composer',
        );
    }

    #[WithSkillsJson([
        'trusted' => ['acme/skills-basic'],
    ])]
    public function fromGithubFiltersOutLocalDonors(): void
    {
        // No remote entries are configured, so --from=github finds
        // nothing to sync. The runner emits the neutral filter
        // message and exits cleanly.
        $process = $this->runSync('--from=github');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::false(
            \is_dir(self::TARGET_DIR),
            'no target should be created when --from=github matches nothing',
        );
        $combined = $process->getOutput() . $process->getErrorOutput();
        Assert::true(
            \str_contains($combined, '--from=github matched no donors'),
            'runner must announce the empty filter result. Got: ' . $combined,
        );
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
}
