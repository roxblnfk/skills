<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance tests for the `composer skills:sync` command.
 *
 * The sandbox project (`tests/Sandbox/project`) installs two stub packages:
 * - {@see Info::PACKAGES_DIR}/acme/skills-basic — `extra.skills.source = ".claude/skills"`
 *     with skills `greeting/` and `code-review/`.
 * - {@see Info::PACKAGES_DIR}/acme/skills-pro — `extra.skills.source = "resources/skills"`
 *     with skills `refactor/` (incl. nested `templates/suggestion.md`) and `migrate/`.
 *
 * Each stub package declares skills via:
 *   `extra.skills.source = "<dir-relative-to-package-root>"`
 * Every subdirectory of that source is one skill; every file in it (recursively) is copied
 * to the project's `.claude/skills/<skill-name>/...`.
 */
#[Test]
final class SkillsSyncTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.claude/skills';

    /**
     * Wipe the synced skills directory before each test so assertions reflect
     * what this run produced, not leftovers from previous runs.
     */
    #[BeforeTest]
    public function clearTargetDir(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
    }

    public function exitsWithSuccessStatus(): void
    {
        $process = $this->runSync();

        Assert::same(
            $process->getExitCode(),
            0,
            'skills:sync must exit with status 0; stderr was: ' . $process->getErrorOutput(),
        );
    }

    public function copiesSkillsFromStandardClaudeDirectory(): void
    {
        $this->runSync();

        Assert::true(
            \is_file(self::TARGET_DIR . '/greeting/SKILL.md'),
            'greeting/SKILL.md must be synced from acme/skills-basic',
        );
        Assert::true(
            \is_file(self::TARGET_DIR . '/code-review/SKILL.md'),
            'code-review/SKILL.md must be synced from acme/skills-basic',
        );
    }

    public function copiesSkillsFromCustomSourceDirectory(): void
    {
        $this->runSync();

        Assert::true(
            \is_file(self::TARGET_DIR . '/refactor/SKILL.md'),
            'refactor/SKILL.md must be synced from acme/skills-pro (resources/skills)',
        );
        Assert::true(
            \is_file(self::TARGET_DIR . '/migrate/SKILL.md'),
            'migrate/SKILL.md must be synced from acme/skills-pro (resources/skills)',
        );
    }

    public function copiesNestedFilesInsideASkill(): void
    {
        $this->runSync();

        Assert::true(
            \is_file(self::TARGET_DIR . '/refactor/templates/suggestion.md'),
            'Nested files inside a skill directory must be copied recursively',
        );
    }

    public function preservesFileContents(): void
    {
        $this->runSync();

        $source = Info::PACKAGES_DIR . '/acme/skills-basic/.claude/skills/greeting/SKILL.md';
        $target = self::TARGET_DIR . '/greeting/SKILL.md';

        Assert::same(
            \file_get_contents($target),
            \file_get_contents($source),
            'Synced file content must match the source byte-for-byte',
        );
    }

    public function ignoresPackagesWithoutSkillsExtra(): void
    {
        // internal/path is installed but declares no extra.skills — sync must
        // not create stray files for it.
        $this->runSync();

        $entries = \is_dir(self::TARGET_DIR)
            ? \array_values(\array_diff(\scandir(self::TARGET_DIR) ?: [], ['.', '..']))
            : [];

        \sort($entries);

        Assert::same(
            $entries,
            ['code-review', 'greeting', 'migrate', 'refactor'],
            'Only skill directories from acme/skills-* should appear in the target',
        );
    }

    public function isIdempotent(): void
    {
        $first = $this->runSync();
        $second = $this->runSync();

        Assert::same($first->getExitCode(), 0, 'first run must succeed');
        Assert::same($second->getExitCode(), 0, 'second run must succeed without errors');
        Assert::true(
            \is_file(self::TARGET_DIR . '/greeting/SKILL.md'),
            'files must still exist after a second sync',
        );
    }

    private function runSync(): Process
    {
        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:sync',
            timeout: 60,
            mustSucceed: false,
        );
    }
}
