<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Config\Mapper\ProjectConfigMigrator;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Composer\WithSkillsJson;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance coverage for the `remote` → `sources` config-key rename in
 * `skills.json`:
 *
 * - `skills:update` (write-mode) renames a legacy `remote` key to
 *   `sources` in place, preserving every other key and its position,
 *   announces the rewrite with a `[migrate]` notice, and syncs normally.
 * - `skills:show` (read-only) reads the alias, emits the `[deprecated]`
 *   notice, and never touches the file.
 * - A file carrying BOTH keys is fatal: the command fails and the file
 *   is left untouched, so the user resolves the ambiguity by hand.
 *
 * Sandbox already has `acme/skills-basic` installed and trusted, so a
 * `sources: []` / `remote: []` list still produces a real Composer-donor
 * sync — the rename behaviour is what is under test, not remote fetching.
 */
#[Test]
final class SkillsSourcesMigrationTest
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
        '$schema' => ProjectConfigMigrator::SCHEMA_URL,
        'target' => '.agents/skills',
        'remote' => [],
        'trusted' => ['acme/skills-basic'],
    ])]
    public function updateRenamesLegacyRemoteKeyInPlaceAndSyncs(): void
    {
        $process = $this->runUpdate();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());

        // A fully-legacy file gets both write-mode fixes in one run:
        // `remote` is renamed to `sources` in its slot, and the legacy
        // `trusted` list is folded into a `dependencies` block in its
        // slot. Every non-legacy key keeps its position.
        $skills = $this->readSkillsJson();
        Assert::same(
            \array_keys($skills),
            ['$schema', 'target', 'sources', 'dependencies'],
            'both migrations run in place, each replacing its key in its original slot',
        );
        Assert::false(\array_key_exists('remote', $skills), 'the deprecated remote key must be gone');
        Assert::false(\array_key_exists('trusted', $skills), 'the legacy trusted key must be folded away');
        Assert::same($skills['sources'], []);
        Assert::same($skills['target'], '.agents/skills');
        Assert::same($skills['dependencies'], ['composer' => ['trusted' => ['acme/skills-basic']]]);

        $combined = $process->getOutput() . $process->getErrorOutput();
        Assert::true(
            \str_contains($combined, '[migrate] renamed "remote" to "sources" in skills.json'),
            'update must announce the in-place rename. Got: ' . $combined,
        );
        Assert::true(
            \str_contains($combined, '[migrate] restructured "trusted" into "dependencies" in skills.json'),
            'update must announce the dependency restructure. Got: ' . $combined,
        );

        // The sync proceeded against the Composer donor with the renamed config.
        Assert::true(
            \is_file(self::TARGET_DIR . '/greeting/SKILL.md'),
            'skills must sync after the key rename',
        );
    }

    #[WithSkillsJson([
        '$schema' => ProjectConfigMigrator::SCHEMA_URL,
        'target' => '.agents/skills',
        'remote' => [],
        'trusted' => ['acme/skills-basic'],
    ])]
    public function showReadsLegacyRemoteKeyWithoutRewritingTheFile(): void
    {
        $before = (string) \file_get_contents(self::SKILLS_JSON);

        $process = $this->runShow();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::same(
            \file_get_contents(self::SKILLS_JSON),
            $before,
            'show is read-only and must not rewrite skills.json',
        );

        $combined = $process->getOutput() . $process->getErrorOutput();
        Assert::true(
            \str_contains($combined, '[deprecated] config key "remote" was renamed to "sources"'),
            'show must surface the deprecation notice for the legacy key. Got: ' . $combined,
        );
    }

    #[WithSkillsJson([
        '$schema' => ProjectConfigMigrator::SCHEMA_URL,
        'target' => '.agents/skills',
        'sources' => [],
        'remote' => [],
    ])]
    public function updateFailsWhenBothSourcesAndRemoteArePresent(): void
    {
        $before = (string) \file_get_contents(self::SKILLS_JSON);

        $process = $this->runUpdate();

        Assert::notSame(
            $process->getExitCode(),
            0,
            'a file with both keys must fail the run; stderr: ' . $process->getErrorOutput(),
        );
        $combined = $process->getOutput() . $process->getErrorOutput();
        Assert::true(
            \str_contains($combined, 'both "sources" and "remote" are present; keep "sources" only'),
            'the both-keys error must name the conflict. Got: ' . $combined,
        );
        Assert::same(
            \file_get_contents(self::SKILLS_JSON),
            $before,
            'the ambiguous file must be left untouched for the user to resolve',
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

    private function runShow(): Process
    {
        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:show',
            timeout: 60,
            mustSucceed: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readSkillsJson(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode(
            (string) \file_get_contents(self::SKILLS_JSON),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        return $decoded;
    }
}
