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
 * Acceptance coverage for skills marked `metadata.internal` in their
 * SKILL.md frontmatter.
 *
 * `acme/skills-internal` is a direct dependency of the sandbox, so it is
 * trusted implicitly. Its `skills/` directory holds the user-facing
 * `usage-guide` next to two contributor skills: `release-checklist`
 * (block-style `metadata:` with `internal: true`) and `bug-repro`
 * (flow-style `metadata: {internal: "1"}`).
 *
 * `local-skills-internal/` in the sandbox project is a bare skills
 * directory with `dir-public` and the internal `dir-contributor`; declared
 * as a `dir` source it stands for a project syncing its own contributor
 * skills into its own repository.
 */
#[Test]
final class SkillsInternalTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';
    private const DONOR = 'acme/skills-internal';
    private const DONOR_SKILLS_DIR = Info::PACKAGES_DIR . '/acme/skills-internal/skills';

    #[BeforeTest]
    public function clearTargetDir(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
    }

    public function syncFromAComposerDonorSkipsItsInternalSkills(): void
    {
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \is_file(self::TARGET_DIR . '/usage-guide/SKILL.md'),
            'the user-facing skill must be installed. stdout: ' . $process->getOutput(),
        );
        Assert::false(
            \is_dir(self::TARGET_DIR . '/release-checklist'),
            'a skill with block-style metadata.internal: true must not be installed',
        );
        Assert::false(
            \is_dir(self::TARGET_DIR . '/bug-repro'),
            'a skill with flow-style metadata {internal: "1"} must not be installed',
        );
    }

    public function syncScopedToTheDonorStillSkipsItsInternalSkills(): void
    {
        $process = $this->runSync(self::DONOR);

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::TARGET_DIR . '/usage-guide/SKILL.md'));
        Assert::false(\is_dir(self::TARGET_DIR . '/release-checklist'));
        Assert::false(\is_dir(self::TARGET_DIR . '/bug-repro'));
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills-internal'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function aDirSourceInTheProjectConfigInstallsItsInternalSkills(): void
    {
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::TARGET_DIR . '/dir-public/SKILL.md'));
        Assert::true(
            \is_file(self::TARGET_DIR . '/dir-contributor/SKILL.md'),
            'an internal skill from the project\'s own dir source must be installed. stdout: '
            . $process->getOutput(),
        );
        Assert::false(
            \is_dir(self::TARGET_DIR . '/release-checklist'),
            'the dir source exception must not leak to Composer donors',
        );
    }

    public function showListsSkippedInternalSkillsWithAMarker(): void
    {
        $process = $this->runShow();
        $out = $process->getOutput();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        foreach (['release-checklist', 'bug-repro'] as $skill) {
            $line = $this->lineNaming($out, $skill);
            Assert::true($line !== null, $skill . ' must be listed by show. stdout: ' . $out);
            Assert::true(
                \stripos((string) $line, 'internal') !== false,
                $skill . ' must carry an internal marker. line: ' . $line,
            );
        }
        $publicLine = $this->lineNaming($out, 'usage-guide');
        Assert::true($publicLine !== null, 'usage-guide must be listed by show. stdout: ' . $out);
        Assert::false(
            \stripos((string) $publicLine, 'internal') !== false,
            'the user-facing skill must not carry the internal marker. line: ' . $publicLine,
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
        'clean' => true,
    ])]
    public function cleanSyncRemovesASkillThatBecameInternalSinceItWasInstalled(): void
    {
        $this->plantInstalledCopy('release-checklist');

        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::false(
            \is_dir(self::TARGET_DIR . '/release-checklist'),
            'a clean sync must remove the copy of a skill that is now internal',
        );
        Assert::true(\is_file(self::TARGET_DIR . '/usage-guide/SKILL.md'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Copy a donor skill into the target with its `metadata` block dropped,
     * standing in for the copy installed before the donor marked it internal.
     *
     * @param non-empty-string $name
     */
    private function plantInstalledCopy(string $name): void
    {
        $source = (string) \file_get_contents(self::DONOR_SKILLS_DIR . '/' . $name . '/SKILL.md');
        $content = (string) \preg_replace('/^metadata:.*\R(?:[ \t]+.*\R)*/m', '', $source);
        \mkdir(self::TARGET_DIR . '/' . $name, 0o777, true);
        \file_put_contents(self::TARGET_DIR . '/' . $name . '/SKILL.md', $content);
    }

    /**
     * The first output line naming the skill as a whole word, so
     * `skills-internal` in a donor header does not match a skill row.
     */
    private function lineNaming(string $output, string $skill): ?string
    {
        foreach (\explode("\n", $output) as $line) {
            if (\preg_match('/(?<![\w-])' . \preg_quote($skill, '/') . '(?![\w-])/', $line) === 1) {
                return $line;
            }
        }

        return null;
    }

    private function runSync(string ...$args): Process
    {
        return $this->runComposer('skills:update', $args);
    }

    private function runShow(string ...$args): Process
    {
        return $this->runComposer('skills:show', $args);
    }

    /**
     * @param list<string> $args
     */
    private function runComposer(string $command, array $args): Process
    {
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
