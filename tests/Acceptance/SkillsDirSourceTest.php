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
 * Acceptance coverage for the `dir` source adapter — the offline path
 * `skills:update` never had before.
 *
 * The sandbox ships a committed fixture directory at
 * `tests/Sandbox/project/local-skills/` with two bare skills
 * (`dir-hello`, `dir-extra`). Each test injects a `skills.json` whose
 * `sources[]` declares that directory as a `from: "dir"` donor and runs
 * the real `composer skills:update` / `skills:show` against it — no
 * network, no archive, no cache.
 */
#[Test]
final class SkillsDirSourceTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';

    #[BeforeTest]
    public function clearTargetDir(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function updateCopiesSkillsFromDirSource(): void
    {
        $process = $this->runUpdate();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \is_file(self::TARGET_DIR . '/dir-hello/SKILL.md'),
            'dir source skill must be copied into the target. stderr: ' . $process->getErrorOutput(),
        );
        Assert::true(\is_file(self::TARGET_DIR . '/dir-extra/SKILL.md'));
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills', 'skills' => ['dir-hello']],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function updateWithAllowlistSyncsOnlyTheNamedSkill(): void
    {
        $process = $this->runUpdate();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::TARGET_DIR . '/dir-hello/SKILL.md'));
        Assert::false(
            \is_file(self::TARGET_DIR . '/dir-extra/SKILL.md'),
            'dir-extra is not on the allowlist and must not be copied',
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './no-such-dir'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function missingDirSourceWarnsUnderVerboseAndStillSucceeds(): void
    {
        $process = $this->runUpdate('-v');
        $combined = $process->getOutput() . $process->getErrorOutput();

        // A missing directory degrades gracefully: the run still exits 0.
        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \str_contains($combined, 'dir ./no-such-dir')
            && \str_contains($combined, 'directory does not exist'),
            'the per-entry warning must name the missing directory. Got: ' . $combined,
        );
        Assert::false(\is_file(self::TARGET_DIR . '/dir-hello/SKILL.md'));
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function showListsTheDirDonorAndItsSkills(): void
    {
        $process = $this->runShow();
        $out = $process->getOutput();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        // The derived donor name is `<project-basename>/local-skills`.
        Assert::true(
            \str_contains($out, 'local-skills'),
            'show must list the dir donor. Got: ' . $out,
        );
        Assert::true(
            \str_contains($out, 'dir-hello'),
            'show must list the dir donor skills. Got: ' . $out,
        );
    }

    private function runUpdate(string ...$args): Process
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

    private function runShow(): Process
    {
        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:show',
            timeout: 60,
            mustSucceed: false,
        );
    }
}
