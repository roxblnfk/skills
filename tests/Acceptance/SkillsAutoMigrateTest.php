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
 * Acceptance coverage for the automatic migration of legacy inline
 * `extra.skills` into `skills.json`.
 *
 * Contract:
 *
 * - Write-mode commands (`skills:update`, `post-update-cmd` autosync)
 *   migrate on first contact.
 * - Read-only paths (`skills:show`, `post-install-cmd` autosync) do
 *   NOT migrate; show emits a notice pointing the user at update.
 * - Once `skills.json` exists, `composer.json` is left alone forever.
 */
#[Test]
final class SkillsAutoMigrateTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';
    private const SKILLS_JSON = Info::PROJECT_DIR . '/skills.json';
    private const COMPOSER_JSON = Info::PROJECT_DIR . '/composer.json';

    #[BeforeTest]
    public function snapshotAndClear(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        SandboxStateGuard::snapshot();
    }

    #[AfterTest]
    public function restore(): void
    {
        SandboxStateGuard::restore();
    }

    // ── update migrates ─────────────────────────────────────────────────

    #[WithSandboxExtras([
        'target' => 'auto-migrate-target/skills',
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function updateMigratesInlineIntoSkillsJsonOnFirstRun(): void
    {
        // Before run: no skills.json, inline extra.skills has project keys.
        Assert::false(\is_file(self::SKILLS_JSON), 'pre-condition: no skills.json yet');

        $process = $this->runUpdate();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());

        // After run: skills.json exists with migrated keys.
        Assert::true(\is_file(self::SKILLS_JSON), 'skills.json must be created');
        $skills = $this->readSkillsJson();
        Assert::same($skills['target'] ?? null, 'auto-migrate-target/skills');
        // Flat `trusted` folds into the `dependencies.composer` block.
        Assert::false(\array_key_exists('trusted', $skills));
        Assert::same(
            $skills['dependencies'] ?? null,
            ['composer' => ['trusted' => ['acme/skills-basic', 'acme/skills-pro']]],
        );
        Assert::true(\array_key_exists('$schema', $skills));

        // composer.json no longer carries the migrated keys.
        $composer = $this->readComposerJson();
        $remaining = $composer['extra']['skills'] ?? [];
        Assert::false(\array_key_exists('target', $remaining));
        Assert::false(\array_key_exists('trusted', $remaining));

        // [migrate] line announces what happened on stdout.
        Assert::true(
            \str_contains($process->getOutput(), '[migrate]'),
            'output must announce the migration. Got: ' . $process->getOutput(),
        );

        // And the actual sync proceeded with the new config.
        Assert::true(
            \is_file(Info::PROJECT_DIR . '/auto-migrate-target/skills/greeting/SKILL.md'),
            'skills must land at the migrated target',
        );

        // Cleanup of the new target dir before AfterTest restores composer.json.
        Filesystem::removeRecursive(Info::PROJECT_DIR . '/auto-migrate-target');
    }

    public function updateIsNoOpForComposerJsonWhenSkillsJsonAlreadyExists(): void
    {
        // Pre-create skills.json in the modern `dependencies` form so the
        // precedence path picks it and neither the inline relocation nor
        // the in-place restructure has anything to do. The sandbox
        // composer.json still has its inline `trusted` block; we should
        // NOT touch it.
        \file_put_contents(self::SKILLS_JSON, \json_encode([
            'dependencies' => ['composer' => ['trusted' => ['acme/skills-basic']]],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $composerBefore = (string) \file_get_contents(self::COMPOSER_JSON);

        $process = $this->runUpdate();

        Assert::same($process->getExitCode(), 0);
        Assert::same(
            \file_get_contents(self::COMPOSER_JSON),
            $composerBefore,
            'composer.json must be byte-for-byte unchanged when skills.json already exists',
        );
        // No [migrate] line on this run.
        Assert::false(
            \str_contains($process->getOutput(), '[migrate]'),
            'no migration must happen when skills.json already exists',
        );
    }

    public function updateIsNoOpForComposerJsonWhenNoInlineProjectKeys(): void
    {
        // composer.json has no inline `extra.skills` project keys
        // (the WithSandboxExtras attribute is absent). The runner has
        // nothing to migrate; composer.json stays untouched.
        $composerBefore = (string) \file_get_contents(self::COMPOSER_JSON);

        // Strip the default inline keys so this test really has "no
        // inline project keys" precondition. We do this in-test (not
        // via WithSandboxExtras) so the snapshot guard restores
        // afterwards.
        $decoded = \json_decode($composerBefore, true, flags: \JSON_THROW_ON_ERROR);
        unset($decoded['extra']['skills']);
        \file_put_contents(
            self::COMPOSER_JSON,
            \json_encode($decoded, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n",
        );
        $strippedComposer = (string) \file_get_contents(self::COMPOSER_JSON);

        $process = $this->runUpdate();

        Assert::same($process->getExitCode(), 0);
        Assert::same(
            \file_get_contents(self::COMPOSER_JSON),
            $strippedComposer,
            'composer.json must remain identical when nothing to migrate',
        );
        Assert::false(
            \is_file(self::SKILLS_JSON),
            'no stub skills.json must be auto-created on plain update',
        );
    }

    // ── post-install vs post-update ─────────────────────────────────────

    #[WithSandboxExtras([
        'target' => 'auto-migrate-target/skills',
        'trusted' => ['acme/skills-basic'],
        'auto-sync' => true,
    ])]
    public function postUpdateHookMigrates(): void
    {
        // The post-update-cmd hook handles the migration step because
        // composer update is the right moment to rewrite composer.json.
        Assert::false(\is_file(self::SKILLS_JSON));

        $process = $this->runScript('post-update-cmd');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::SKILLS_JSON), 'post-update-cmd should have migrated');
        Filesystem::removeRecursive(Info::PROJECT_DIR . '/auto-migrate-target');
    }

    #[WithSandboxExtras([
        'target' => 'auto-migrate-target/skills',
        'trusted' => ['acme/skills-basic'],
        'auto-sync' => true,
    ])]
    public function postInstallHookDoesNotMigrate(): void
    {
        // post-install is a fetch step; surprising the user with a
        // composer.json rewrite mid-install is the wrong default.
        // The hook still syncs (auto-sync is on), but composer.json
        // stays as the interceptor left it.
        $composerBefore = (string) \file_get_contents(self::COMPOSER_JSON);
        Assert::false(\is_file(self::SKILLS_JSON));

        $process = $this->runScript('post-install-cmd');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::same(
            \file_get_contents(self::COMPOSER_JSON),
            $composerBefore,
            'post-install-cmd must NOT rewrite composer.json',
        );
        Assert::false(
            \is_file(self::SKILLS_JSON),
            'post-install-cmd must NOT create skills.json',
        );

        // But the sync did happen — skills are at the inline-configured
        // target (we read inline as fallback when no skills.json exists).
        Assert::true(
            \is_file(Info::PROJECT_DIR . '/auto-migrate-target/skills/greeting/SKILL.md'),
            'post-install-cmd should still sync, just without migrating',
        );
        Filesystem::removeRecursive(Info::PROJECT_DIR . '/auto-migrate-target');
    }

    // ── migration failure propagates ────────────────────────────────────

    #[WithSandboxExtras([
        'target' => 'auto-migrate-target/skills',
        'auto-sync' => 'yes',  // intentionally malformed
    ])]
    public function updateFailsLoudlyWhenInlineIsMalformed(): void
    {
        // The migrator refuses to relocate broken config; the runner
        // turns Failed into a non-zero exit code instead of papering
        // over the problem.
        $process = $this->runUpdate();

        Assert::notSame(
            $process->getExitCode(),
            0,
            'malformed inline must fail the run; stderr: ' . $process->getErrorOutput(),
        );
        Assert::true(
            \str_contains($process->getErrorOutput(), 'cannot auto-migrate'),
            'stderr must explain the migration refusal. Got: ' . $process->getErrorOutput(),
        );
        Assert::false(
            \is_file(self::SKILLS_JSON),
            'no skills.json must be written when migration fails pre-flight',
        );
    }

    // ── show is read-only ───────────────────────────────────────────────

    #[WithSandboxExtras([
        'target' => '.agents/skills',
        'trusted' => ['acme/skills-basic'],
    ])]
    public function showDoesNotMigrate(): void
    {
        // Inject inline project keys explicitly via the attribute so
        // this test does not depend on whatever the sandbox happens
        // to ship with — particularly important on CI where tests
        // across classes might run in an order that leaves the
        // sandbox in a different state than local runs do.
        $composerBefore = (string) \file_get_contents(self::COMPOSER_JSON);
        Assert::false(\is_file(self::SKILLS_JSON));

        $process = ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:show',
            timeout: 60,
            mustSucceed: false,
        );

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::same(
            \file_get_contents(self::COMPOSER_JSON),
            $composerBefore,
            'show must not mutate composer.json',
        );
        Assert::false(\is_file(self::SKILLS_JSON), 'show must not create skills.json');
    }

    #[WithSandboxExtras([
        'target' => '.agents/skills',
        'trusted' => ['acme/skills-basic'],
    ])]
    public function showEmitsLegacyNoticeWhenInlineDetected(): void
    {
        // The notice exists so a user staring at "still works on inline
        // config" output knows there's a one-command migration available.
        // We inject inline project keys via WithSandboxExtras so the
        // notice fires deterministically regardless of cross-class
        // test ordering on CI.
        $process = ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:show',
            timeout: 60,
            mustSucceed: false,
        );

        $combined = $process->getOutput() . $process->getErrorOutput();
        Assert::true(
            \str_contains($combined, 'legacy inline config'),
            'show should hint at migration when inline keys exist. Got: ' . $combined,
        );
        Assert::true(
            \str_contains($combined, 'skills:update'),
            'hint should name the command to run. Got: ' . $combined,
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function runUpdate(): Process
    {
        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:update',
            timeout: 60,
            mustSucceed: false,
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

    /**
     * @return array<string, mixed>
     */
    private function readComposerJson(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode(
            (string) \file_get_contents(self::COMPOSER_JSON),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        return $decoded;
    }
}
