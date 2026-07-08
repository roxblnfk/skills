<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance coverage for `skills:add` against a local directory — the
 * first fully offline `skills:add` path (no network, no archive, no
 * cache). The sandbox ships committed fixture directories:
 * `local-skills/` with two bare skills (`dir-hello`, `dir-extra`);
 * `local-composer-skill/` — a composer-shaped donor (its composer.json
 * `name` is `acme/dir-composer`) shipping one skill; and `not-a-donor/`
 * — a directory with neither a donor manifest nor SKILL.md files.
 *
 * Each test runs the real `composer skills:add ./local-skills` in the
 * sandbox, then asserts on the `sources[]` entry it wrote and on what
 * the follow-up sync copied into the default target. `skills.json` is
 * managed by hand (deleted before and after) since the command under
 * test is the one that creates it — the {@see WithSkillsJson} attribute
 * would fight it.
 */
#[Test]
final class SkillsAddDirTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';
    private const SKILLS_JSON = Info::PROJECT_DIR . '/skills.json';

    #[BeforeTest]
    public static function reset(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        if (\is_file(self::SKILLS_JSON)) {
            @\unlink(self::SKILLS_JSON);
        }
    }

    #[AfterTest]
    public static function cleanup(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        if (\is_file(self::SKILLS_JSON)) {
            @\unlink(self::SKILLS_JSON);
        }
    }

    public function addWritesTheEntryAndSyncsTheDirSkills(): void
    {
        $process = $this->runAdd('./local-skills');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));

        $sources = $this->readSources();
        Assert::count($sources, 1, 'exactly one dir source must be registered');
        Assert::same($sources[0]['from'] ?? null, 'dir');
        Assert::same($sources[0]['path'] ?? null, './local-skills');
        Assert::false(\array_key_exists('url', $sources[0]), 'dir entries carry no url');

        // The follow-up sync ran (no --no-sync), so both fixture skills
        // must sit in the default target.
        Assert::true(
            \is_file(self::TARGET_DIR . '/dir-hello/SKILL.md'),
            'dir-hello must be synced. ' . $this->diagnostics($process),
        );
        Assert::true(\is_file(self::TARGET_DIR . '/dir-extra/SKILL.md'));
    }

    public function addWithAllowlistSyncsOnlyTheNamedSkill(): void
    {
        $process = $this->runAdd('./local-skills --skill=dir-hello');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));

        $sources = $this->readSources();
        Assert::same($sources[0]['skills'] ?? null, ['dir-hello']);

        Assert::true(\is_file(self::TARGET_DIR . '/dir-hello/SKILL.md'));
        Assert::false(
            \is_file(self::TARGET_DIR . '/dir-extra/SKILL.md'),
            'dir-extra is not on the allowlist and must not be copied',
        );
    }

    public function addWithNoSyncWritesTheEntryWithoutCopying(): void
    {
        $process = $this->runAdd('./local-skills --no-sync');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));

        $sources = $this->readSources();
        Assert::count($sources, 1);
        Assert::same($sources[0]['path'] ?? null, './local-skills');

        Assert::false(
            \is_dir(self::TARGET_DIR),
            '--no-sync must leave the target untouched. ' . $this->diagnostics($process),
        );
    }

    public function addComposerShapedDirSyncsUnderItsComposerJsonName(): void
    {
        // The `local-composer-skill` fixture carries its own composer.json
        // (`name: acme/dir-composer`), so the sync pipeline registers it
        // under that name — not the path-derived hint. The add-time
        // inspection must hand that same name to the scoped follow-up
        // sync, otherwise the sync filters on the wrong donor and copies
        // nothing (the exact regression this test pins down).
        $process = $this->runAdd('./local-composer-skill');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));

        $sources = $this->readSources();
        Assert::count($sources, 1);
        Assert::same($sources[0]['path'] ?? null, './local-composer-skill');

        Assert::true(
            \is_file(self::TARGET_DIR . '/composer-hello/SKILL.md'),
            'the composer-shaped dir donor must have its skill synced. ' . $this->diagnostics($process),
        );
    }

    public function addNonDonorDirFailsCleanlyWithoutWritingSkillsJson(): void
    {
        // The `not-a-donor` fixture exists but ships no composer.json
        // donor declaration and no SKILL.md files. The add-time inspection
        // must refuse it with a clear error and leave no skills.json
        // behind — a directory the sync would ignore never registers.
        $process = $this->runAdd('./not-a-donor');

        Assert::notSame($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getErrorOutput(), 'dir ./not-a-donor'),
            'the error must name the refused path. ' . $this->diagnostics($process),
        );
        Assert::true(
            \str_contains($process->getErrorOutput(), 'neither a composer.json'),
            'the error must explain the non-donor shape. ' . $this->diagnostics($process),
        );
        Assert::false(
            \is_file(self::SKILLS_JSON),
            'a refused add writes no skills.json. ' . $this->diagnostics($process),
        );
    }

    /**
     * @param non-empty-string $args
     */
    private function runAdd(string $args): Process
    {
        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:add ' . $args,
            timeout: 60,
            mustSucceed: false,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readSources(): array
    {
        Assert::true(\is_file(self::SKILLS_JSON), 'skills:add must create skills.json');
        /** @var array<string, mixed> $payload */
        $payload = \json_decode(
            (string) \file_get_contents(self::SKILLS_JSON),
            associative: true,
            flags: \JSON_THROW_ON_ERROR,
        );
        /** @var list<array<string, mixed>> $sources */
        $sources = (array) ($payload['sources'] ?? []);
        return $sources;
    }

    private function diagnostics(Process $process): string
    {
        return 'stdout: ' . $process->getOutput() . "\nstderr: " . $process->getErrorOutput();
    }
}
