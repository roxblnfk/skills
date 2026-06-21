<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Composer\WithSandboxExtras;
use LLM\Skills\Tests\Testo\Composer\WithSkillsJson;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance tests for the external `skills.json` config file:
 *
 * - precedence over the inline `extra.skills` block in `composer.json`;
 * - the warning emitted under `-v` when both sources coexist;
 * - the `composer skills:init` migration flow (project keys move out of
 *   `composer.json` into `skills.json`, donor `source` stays behind);
 * - refusal semantics for `init` (no `--force`).
 *
 * These all run against the standard sandbox at `tests/Sandbox/project`,
 * exercising the full pipeline: Composer plugin → runner → mapper →
 * filesystem writes.
 */
#[Test]
final class SkillsJsonConfigTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';
    private const EXTERNAL_TARGET = Info::PROJECT_DIR . '/external-target/skills';
    private const INLINE_TARGET = Info::PROJECT_DIR . '/inline-target/skills';
    private const SKILLS_JSON = Info::PROJECT_DIR . '/skills.json';
    private const COMPOSER_JSON = Info::PROJECT_DIR . '/composer.json';

    /**
     * A location *outside* the sandbox project root (a sibling of it under
     * `tests/Sandbox/`). Used by the `path-from-root` tests, where the
     * containment root re-anchors to `tests/Sandbox` and the target lands
     * in a sibling of the project (`sandbox-escape/...` resolved there).
     */
    private const ESCAPE_DIR = Info::PROJECT_DIR . '/../sandbox-escape';

    private ?string $originalComposerJson = null;

    /**
     * Snapshot the sandbox `composer.json` so we can restore it
     * verbatim after the `skills:init` tests, which rewrite the file
     * in place. Tests that already use {@see WithSandboxExtras} do
     * not strictly need this (the interceptor restores too), but a
     * second guard is cheap and makes the init tests independent.
     *
     * NOTE: skills.json deletion happens in {@see restoreComposerJson()}
     * only, not here — the {@see WithSkillsJson} interceptor runs
     * *outside* the BeforeTest hook, so a delete here would erase
     * the file the interceptor just wrote.
     */
    #[BeforeTest]
    public function snapshotAndClear(): void
    {
        $raw = \file_get_contents(self::COMPOSER_JSON);
        $this->originalComposerJson = $raw === false ? null : $raw;

        Filesystem::removeRecursive(self::TARGET_DIR);
        Filesystem::removeRecursive(Info::PROJECT_DIR . '/external-target');
        Filesystem::removeRecursive(Info::PROJECT_DIR . '/inline-target');
        Filesystem::removeRecursive(self::ESCAPE_DIR);
    }

    #[AfterTest]
    public function restoreComposerJson(): void
    {
        if ($this->originalComposerJson !== null) {
            \file_put_contents(self::COMPOSER_JSON, $this->originalComposerJson);
        }
        if (\is_file(self::SKILLS_JSON)) {
            @\unlink(self::SKILLS_JSON);
        }
        Filesystem::removeRecursive(self::ESCAPE_DIR);
    }

    // ── precedence ──────────────────────────────────────────────────────

    #[WithSandboxExtras([
        'target' => 'inline-target/skills',
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    #[WithSkillsJson([
        'target' => 'external-target/skills',
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function skillsJsonOverridesInlineExtraSkills(): void
    {
        // Inline says "inline-target/skills"; external says
        // "external-target/skills". The mapper must pick external —
        // the file is the new source of truth.
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \is_file(self::EXTERNAL_TARGET . '/greeting/SKILL.md'),
            'skills.json target must win over inline target',
        );
        Assert::false(
            \is_dir(self::INLINE_TARGET),
            'inline target must be ignored entirely',
        );
    }

    #[WithSandboxExtras([
        'target' => 'inline-target/skills',
        'trusted' => ['acme/skills-basic'],
        'auto-sync' => true,
    ])]
    #[WithSkillsJson([
        'target' => 'external-target/skills',
        'trusted' => ['acme/skills-basic'],
    ])]
    public function shadowedInlineKeysAreReportedUnderVerbose(): void
    {
        // The warning is under -v so day-to-day output stays quiet;
        // when the user passes -v they get an explanation of which
        // inline keys their skills.json shadowed.
        $process = $this->runSync('-v');

        Assert::same($process->getExitCode(), 0);
        $combined = $process->getOutput() . $process->getErrorOutput();
        Assert::true(
            \str_contains($combined, 'skills.json present'),
            '-v output must announce the skills.json shadowing. Got: ' . $combined,
        );
        Assert::true(
            \str_contains($combined, 'target'),
            'shadowed key list should name the colliding inline keys. Got: ' . $combined,
        );
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic'],
    ])]
    #[WithSkillsJson([
        'target' => 'external-target/skills',
        'trusted' => ['acme/skills-basic'],
    ])]
    public function inlineKeysNotInProjectListAreNotReportedAsShadowed(): void
    {
        // Inline has only `trusted` (a project key). External shadows
        // it. The donor-side key `source` is absent, so the shadowed
        // list should contain just `trusted` — no false positives.
        $process = $this->runSync('-v');

        Assert::same($process->getExitCode(), 0);
        Assert::true(\str_contains($process->getErrorOutput(), 'trusted'));
    }

    public function inlineConfigStillWorksWhenSkillsJsonIsAbsent(): void
    {
        // No skills.json. The existing 1.x inline contract must keep
        // working unchanged — this regression-guards the fallback
        // branch of the decision tree.
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::TARGET_DIR . '/greeting/SKILL.md'));
    }

    #[WithSkillsJson([
        'target' => 'external-target/skills',
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function skillsJsonAloneDrivesTargetWhenInlineIsAbsent(): void
    {
        // Sandbox's default composer.json has its own `extra.skills`
        // block (with `trusted`), but no `target`. External provides
        // the target. The combination must work.
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \is_file(self::EXTERNAL_TARGET . '/greeting/SKILL.md'),
            'skills.json target must apply when used alone',
        );
    }

    public function malformedSkillsJsonFailsTheRunWithClearPrefix(): void
    {
        \file_put_contents(self::SKILLS_JSON, '{ this is not json');

        $process = $this->runSync();

        Assert::notSame($process->getExitCode(), 0, 'malformed skills.json must fail');
        Assert::true(
            \str_contains($process->getErrorOutput(), 'skills.json:'),
            'error must be prefixed with skills.json: so the user knows which file. Got: '
            . $process->getErrorOutput(),
        );
    }

    #[WithSkillsJson([
        'target' => 'external-target/skills',
        'rogue' => 'value',
    ])]
    public function unknownKeyInSkillsJsonIsFatal(): void
    {
        $process = $this->runSync();

        Assert::notSame($process->getExitCode(), 0);
        Assert::true(
            \str_contains($process->getErrorOutput(), 'rogue'),
            'unknown-key error must name the offending key. Got: ' . $process->getErrorOutput(),
        );
    }

    // ── security: paths must stay inside the project root ──────────────

    #[WithSkillsJson([
        'target' => '../escape/skills',
        'trusted' => ['acme/skills-basic'],
    ])]
    public function targetEscapingProjectRootViaSkillsJsonIsRejected(): void
    {
        // SyncPlanner's containment guard catches the escape regardless
        // of where the value came from (CLI, inline composer.json,
        // skills.json). This test pins the contract specifically for
        // the skills.json source so a future loader refactor cannot
        // silently bypass the planner.
        $process = $this->runSync();

        Assert::notSame($process->getExitCode(), 0, '../escape via skills.json must fail');
        Assert::true(
            \str_contains($process->getErrorOutput(), 'outside the project root'),
            'stderr must explain containment failure. Got: ' . $process->getErrorOutput(),
        );
        Assert::false(
            \is_dir(Info::PROJECT_DIR . '/../escape'),
            'no directory must be created outside the project root',
        );
    }

    #[WithSkillsJson([
        'target' => '/tmp/absolute-escape',
        'trusted' => ['acme/skills-basic'],
    ])]
    public function absoluteTargetOutsideProjectRootViaSkillsJsonIsRejected(): void
    {
        // Absolute paths are honoured (matching the legacy contract),
        // but they still must live inside the project. /tmp/...
        // obviously does not.
        $process = $this->runSync();

        Assert::notSame($process->getExitCode(), 0);
        Assert::true(
            \str_contains($process->getErrorOutput(), 'outside the project root'),
            'stderr must explain containment failure. Got: ' . $process->getErrorOutput(),
        );
        Assert::false(
            \is_dir('/tmp/absolute-escape'),
            'no absolute escape directory must be created',
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'aliases' => ['../escape-alias'],
        'trusted' => ['acme/skills-basic'],
    ])]
    public function aliasEscapingProjectRootViaSkillsJsonIsRejected(): void
    {
        // Same guard for aliases: a junction at ../escape-alias would
        // expose an arbitrary parent location through the project tree.
        $process = $this->runSync();

        Assert::notSame($process->getExitCode(), 0, 'alias ../escape via skills.json must fail');
        Assert::true(
            \str_contains($process->getErrorOutput(), 'outside the project root'),
            'stderr must explain containment failure. Got: ' . $process->getErrorOutput(),
        );
        Assert::false(
            \file_exists(Info::PROJECT_DIR . '/../escape-alias'),
            'no junction must be created outside the project root',
        );
    }

    // ── path-from-root: re-anchor the containment root ──────────────────

    #[WithSkillsJson([
        'target' => 'sandbox-escape/skills',
        'path-from-root' => 'project',
        'trusted' => ['acme/skills-basic'],
    ])]
    public function pathFromRootReanchorsTargetAboveTheProjectRoot(): void
    {
        // The sandbox project lives at tests/Sandbox/project. Declaring
        // path-from-root "project" climbs one verified level to
        // tests/Sandbox, so a plain (no-`..`) target lands in a sibling of
        // the project — the positive counterpart to
        // targetEscapingProjectRootViaSkillsJsonIsRejected above.
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \is_file(self::ESCAPE_DIR . '/skills/greeting/SKILL.md'),
            'skills must be synced under the re-anchored root. stderr: ' . $process->getErrorOutput(),
        );
    }

    #[WithSkillsJson([
        'target' => 'sandbox-escape/skills',
        'aliases' => ['../outside-root'],
        'path-from-root' => 'project',
        'trusted' => ['acme/skills-basic'],
    ])]
    public function aliasesStayWithinReanchoredRoot(): void
    {
        // path-from-root widens the boundary to tests/Sandbox, it does not
        // remove it: the target is legitimately inside that root, but an
        // alias climbing above it (../outside-root) is still rejected.
        $process = $this->runSync();

        Assert::notSame($process->getExitCode(), 0, 'alias above the re-anchored root must fail');
        Assert::true(
            \str_contains($process->getErrorOutput(), 'outside the project root'),
            'stderr must explain the alias containment failure. Got: ' . $process->getErrorOutput(),
        );
    }

    // ── skills:init ─────────────────────────────────────────────────────

    #[WithSandboxExtras([
        'target' => 'external-target/skills',
        'trusted' => ['acme/skills-basic'],
    ])]
    public function initMigratesInlineProjectKeysIntoSkillsJson(): void
    {
        $process = $this->runInit();

        Assert::same(
            $process->getExitCode(),
            0,
            'init must succeed; stderr: ' . $process->getErrorOutput(),
        );

        // skills.json now carries the migrated project keys.
        Assert::true(\is_file(self::SKILLS_JSON), 'skills.json must exist after init');
        $skills = $this->readSkillsJson();
        Assert::same($skills['target'] ?? null, 'external-target/skills');
        Assert::same($skills['trusted'] ?? null, ['acme/skills-basic']);
        Assert::true(
            \array_key_exists('$schema', $skills),
            '$schema pointer must be emitted into the generated file',
        );

        // composer.json no longer carries the migrated keys.
        $composer = $this->readComposerJson();
        $remainingSkills = $composer['extra']['skills'] ?? [];
        Assert::false(
            \array_key_exists('target', $remainingSkills),
            'target must be removed from composer.json extra.skills',
        );
        Assert::false(\array_key_exists('trusted', $remainingSkills));
    }

    #[WithSandboxExtras([
        'target' => 'external-target/skills',
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function followUpSyncAfterInitReadsFromSkillsJson(): void
    {
        // End-to-end migration check: init the file, then run skills:update.
        // The output must come out of skills.json, not the now-empty
        // inline block (which still drives behaviour for projects that
        // skipped init).
        $initProc = $this->runInit();
        Assert::same($initProc->getExitCode(), 0, 'init failed: ' . $initProc->getErrorOutput());

        $syncProc = $this->runSync();
        Assert::same(
            $syncProc->getExitCode(),
            0,
            'follow-up sync must succeed. stderr: ' . $syncProc->getErrorOutput(),
        );
        Assert::true(
            \is_file(self::EXTERNAL_TARGET . '/greeting/SKILL.md'),
            'skills must land at the path declared in skills.json after init',
        );
    }

    public function initRefusesToOverwriteExistingSkillsJsonWithoutForce(): void
    {
        \file_put_contents(self::SKILLS_JSON, "{\n  \"target\": \"keep-me\"\n}\n");
        $before = (string) \file_get_contents(self::SKILLS_JSON);

        $process = $this->runInit();

        Assert::notSame($process->getExitCode(), 0, 'second init without --force must fail');
        Assert::true(
            \str_contains($process->getErrorOutput(), '--force'),
            'error message must direct the user to --force. Got: ' . $process->getErrorOutput(),
        );
        Assert::same(
            \file_get_contents(self::SKILLS_JSON),
            $before,
            'existing skills.json must be left untouched on refusal',
        );
    }

    public function initForceFlagAllowsOverwrite(): void
    {
        \file_put_contents(self::SKILLS_JSON, "{}\n");

        $process = $this->runInit('--force');

        Assert::same(
            $process->getExitCode(),
            0,
            '--force must allow overwrite. stderr: ' . $process->getErrorOutput(),
        );
        $skills = $this->readSkillsJson();
        Assert::true(\array_key_exists('$schema', $skills), 'rewrite must emit fresh stub');
    }

    // ── helpers ────────────────────────────────────────────────────────

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

    private function runInit(string ...$args): Process
    {
        $command = 'skills:init';
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
            (string) \file_get_contents(Info::PROJECT_DIR . '/composer.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }

}
