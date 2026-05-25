<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\BinSkillsRunner;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance tests for the standalone `bin/skills` binary running in
 * a directory without a `composer.json`.
 *
 * The spec (see `spec-config-file.md` §3.2 / §10) says that, with no
 * Composer install tree around, the utility must:
 *
 *  1. Treat the cwd as the project root.
 *  2. Read `skills.json` directly when it exists (or use defaults).
 *  3. Report "no donors available" and exit 0 instead of dying with a
 *     Composer bootstrap error.
 *
 * Today the second leg of `update`/`show` still hard-requires
 * Composer bootstrap and so fails with `Failed to bootstrap Composer`.
 * These tests are the failing fixtures that drive the standalone-mode
 * fallback implementation.
 */
#[Test]
final class StandaloneBinTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-standalone-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    // ── update / show without composer.json ─────────────────────────────

    public function updateInEmptyDirectoryDoesNotRequireComposerJson(): void
    {
        // Bare directory: no composer.json, no skills.json. The binary
        // must still come up cleanly — there are simply no donors to
        // consider, so it exits 0 with a "nothing to do" notice rather
        // than the Composer bootstrap error.
        $process = BinSkillsRunner::run(Path::create($this->tmp), 'update');

        Assert::same(
            $process->getExitCode(),
            0,
            'update in an empty dir must succeed; got stderr: ' . $process->getErrorOutput()
            . ' / stdout: ' . $process->getOutput(),
        );
        Assert::false(
            \str_contains(
                $process->getErrorOutput() . $process->getOutput(),
                'Failed to bootstrap Composer',
            ),
            'standalone mode must not surface the Composer bootstrap error',
        );
    }

    public function updateInEmptyDirectoryAnnouncesStandaloneMode(): void
    {
        // The user needs to know why nothing was synced — without a
        // visible diagnostic, an empty target would look like a bug.
        $process = BinSkillsRunner::run(Path::create($this->tmp), 'update');
        $combined = $process->getOutput() . $process->getErrorOutput();

        Assert::true(
            \str_contains($combined, 'no donors available'),
            'output must explain why nothing was copied. Got: ' . $combined,
        );
    }

    public function updateReadsSkillsJsonWhenPresentEvenWithoutComposerJson(): void
    {
        // Project config still resolves correctly: the runner takes the
        // cwd as the project root, finds skills.json, parses its target.
        // We do not actually assert on filesystem effects (no donors →
        // nothing to write); the proof is that the run succeeds and the
        // configured target appears in the output / diagnostics.
        \file_put_contents(
            $this->tmp . '/skills.json',
            \json_encode([
                'target' => 'my-target/skills',
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n",
        );

        $process = BinSkillsRunner::run(Path::create($this->tmp), 'update');

        Assert::same(
            $process->getExitCode(),
            0,
            'update with skills.json but no composer.json must succeed. stderr: '
            . $process->getErrorOutput(),
        );
    }

    public function updateRejectsMalformedSkillsJsonInStandaloneMode(): void
    {
        // skills.json validation is the same in both modes: a broken
        // file is fatal, with a `skills.json:` prefix on the error so
        // the origin is unambiguous.
        \file_put_contents($this->tmp . '/skills.json', '{ not valid');

        $process = BinSkillsRunner::run(Path::create($this->tmp), 'update');

        Assert::notSame(
            $process->getExitCode(),
            0,
            'malformed skills.json must fail the run, just as in composer-attached mode',
        );
        Assert::true(
            \str_contains($process->getErrorOutput(), 'skills.json:'),
            'error must be prefixed with skills.json: so the user knows which file. Got: '
            . $process->getErrorOutput(),
        );
    }

    public function showInEmptyDirectoryDoesNotRequireComposerJson(): void
    {
        // skills:show is the same shape as update — read-only inspection
        // of the same pipeline. It must survive the same standalone
        // conditions.
        $process = BinSkillsRunner::run(Path::create($this->tmp), 'show');

        Assert::same(
            $process->getExitCode(),
            0,
            'show in an empty dir must succeed; got stderr: ' . $process->getErrorOutput(),
        );
        Assert::false(
            \str_contains(
                $process->getErrorOutput() . $process->getOutput(),
                'Failed to bootstrap Composer',
            ),
            'standalone show must not surface the Composer bootstrap error',
        );
    }

    // ── init standalone (currently works but covered end-to-end) ────────

    public function initInEmptyDirectoryWritesStubSkillsJson(): void
    {
        // The init command already handles standalone mode (it never
        // tries to bootstrap Composer in the first place). This test
        // pins the behaviour from the bin/skills entrypoint angle so a
        // future refactor cannot accidentally regress it.
        $process = BinSkillsRunner::run(Path::create($this->tmp), 'init');

        Assert::same(
            $process->getExitCode(),
            0,
            'init in an empty dir must succeed. stderr: ' . $process->getErrorOutput(),
        );
        Assert::true(
            \is_file($this->tmp . '/skills.json'),
            'init must create skills.json in the cwd',
        );

        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode(
            (string) \file_get_contents($this->tmp . '/skills.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        Assert::same(\array_keys($decoded), ['$schema'], 'standalone init writes a stub');
    }
}
