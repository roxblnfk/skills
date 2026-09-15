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
 * With no Composer install tree around the utility, the binary:
 *
 *  1. Treats the cwd as the project root.
 *  2. Reads `skills.json` directly when it exists (or uses defaults).
 *  3. Reports that no donor providers are active and exits 0 — never
 *     surfaces the `Failed to bootstrap Composer` error a naive
 *     `Factory::create()` call would produce.
 *
 * These tests pin the contract so a future refactor of the
 * provider chain cannot accidentally regress standalone mode back to
 * "Composer is mandatory".
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
        // The user-facing notice stays provider-neutral; specifics
        // (which provider was inactive and why) flow through `-v`.
        $process = BinSkillsRunner::run(Path::create($this->tmp), 'update');
        $combined = $process->getOutput() . $process->getErrorOutput();

        Assert::true(
            \str_contains($combined, 'no donor providers are active'),
            'output must explain why nothing was copied. Got: ' . $combined,
        );
    }

    public function updateInEmptyDirectoryExplainsCauseUnderVerbose(): void
    {
        // The neutral notice tells *what*; the -v warning tells *why*
        // (no composer.json at <cwd>). Without this line, a user
        // staring at "no donor providers are active" would not know
        // whether they hit the missing-file path or a bootstrap
        // failure path.
        $process = BinSkillsRunner::run(Path::create($this->tmp), 'update -v');
        $combined = $process->getOutput() . $process->getErrorOutput();

        Assert::true(
            \str_contains($combined, 'no composer.json'),
            '-v output must name the actual cause. Got: ' . $combined,
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

    // ── add standalone (no composer.json) ───────────────────────────────

    public function addInEmptyDirectoryDoesNotRequireComposerJson(): void
    {
        // The standalone `add` used to refuse to run without a project
        // composer.json. That contradicted the design — local Composer
        // is just one provider, and `skills:add` registers a *remote*
        // donor. The command must accept invocation in a bare directory
        // and only fall back to network/parse errors downstream.
        //
        // We pass a deliberately bogus URL to keep the test offline:
        // adapter selection + ref resolution will fail before any HTTP
        // hits, but the failure shape proves we got past the bootstrap
        // gate — no "requires a composer.json" message.
        $process = BinSkillsRunner::run(
            Path::create($this->tmp),
            'add https://example.invalid/none/none --no-sync',
        );
        $combined = $process->getOutput() . $process->getErrorOutput();

        Assert::false(
            \str_contains($combined, 'requires a composer.json'),
            'standalone add must not refuse on missing composer.json. Got: ' . $combined,
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
        Assert::same(
            \array_keys($decoded),
            ['$schema', 'dependencies', 'sources'],
            'standalone init writes a stub with the dependencies + sources knobs visible',
        );
    }

    public function initProposesTheAgentDirectoriesTheProjectAlreadyUses(): void
    {
        // `.claude` and `.cursor` exist, so the project already works with
        // both agents; init wires them to the shared target instead of
        // making the user type the paths.
        \mkdir($this->tmp . '/.claude', 0o777, true);
        \mkdir($this->tmp . '/.cursor', 0o777, true);

        $process = BinSkillsRunner::run(Path::create($this->tmp), 'init');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::same(
            $this->decodeSkillsJson()['aliases'] ?? null,
            ['.claude/skills', '.cursor/skills'],
        );
    }

    public function initLeavesAnAgentDirectoryThatHoldsItsOwnSkillsAlone(): void
    {
        // `.claude/skills` already holds files, so it cannot become a link.
        // It must be reported rather than written into the config.
        \mkdir($this->tmp . '/.claude/skills/greeting', 0o777, true);
        \file_put_contents($this->tmp . '/.claude/skills/greeting/SKILL.md', '# greeting');

        $process = BinSkillsRunner::run(Path::create($this->tmp), 'init');
        $combined = $process->getOutput() . $process->getErrorOutput();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::false(
            \array_key_exists('aliases', $this->decodeSkillsJson()),
            'a directory holding files must not be proposed as an alias. Got: ' . $combined,
        );
        Assert::true(
            \str_contains($combined, 'not proposed as an alias'),
            'the user must be told why the directory was left out. Got: ' . $combined,
        );
    }

    public function quickInitWritesTheDetectedLayout(): void
    {
        // `--quick` answers every question from the layout and confirms with
        // a single prompt; without a TTY the confirmation takes its default.
        \mkdir($this->tmp . '/.claude', 0o777, true);

        $process = BinSkillsRunner::run(Path::create($this->tmp), 'init --quick');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::same($this->decodeSkillsJson()['aliases'] ?? null, ['.claude/skills']);
    }

    public function initSyncsOnceTheConfigIsWritten(): void
    {
        // The sync is what makes `init` leave the project with skills
        // rather than with a file. Here there are no donors to copy, so
        // what proves it ran is the sync's own standalone notice.
        $process = BinSkillsRunner::run(Path::create($this->tmp), 'init');
        $combined = $process->getOutput() . $process->getErrorOutput();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \str_contains($combined, 'no donor providers are active'),
            'init must run a sync after writing the config. Got: ' . $combined,
        );
    }

    public function noSyncFlagWritesTheConfigAndStopsThere(): void
    {
        $process = BinSkillsRunner::run(Path::create($this->tmp), 'init --no-sync');
        $combined = $process->getOutput() . $process->getErrorOutput();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file($this->tmp . '/skills.json'), 'the config is still written');
        Assert::false(
            \str_contains($combined, 'no donor providers are active'),
            '--no-sync must suppress the follow-up sync. Got: ' . $combined,
        );
    }

    public function initWritesTheConfigValuesGivenOnTheCommandLine(): void
    {
        // The scripted setup: one invocation, no prompts, every knob
        // decided by the caller.
        $process = BinSkillsRunner::run(
            Path::create($this->tmp),
            'init --quick --target=.claude/skills --alias=.agents/skills '
            . "--trust=acme/* --trust=myorg/pkg --no-auto-sync --no-discovery",
        );

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());

        $config = $this->decodeSkillsJson();
        Assert::same($config['target'] ?? null, '.claude/skills');
        Assert::same($config['aliases'] ?? null, ['.agents/skills']);
        Assert::same($config['auto-sync'] ?? null, false);
        Assert::same($config['discovery'] ?? null, false);
        // The stub's short `"composer": true` toggle grows into the object
        // form to carry the trust list; `enabled` stays unwritten because
        // enabled is what composer already defaults to.
        Assert::same(
            $config['dependencies'] ?? null,
            ['composer' => ['trusted' => ['acme/*', 'myorg/pkg']]],
        );
    }

    public function givenValuesWinOverTheDetectedLayout(): void
    {
        // `.cursor` is in use, so detection would propose it as an alias.
        // An explicit `--alias` is the user overruling that.
        \mkdir($this->tmp . '/.cursor', 0o777, true);

        $process = BinSkillsRunner::run(Path::create($this->tmp), 'init --alias=.claude/skills');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::same($this->decodeSkillsJson()['aliases'] ?? null, ['.claude/skills']);
    }

    public function anAliasEqualToTheTargetIsRefusedBeforeAnythingIsWritten(): void
    {
        // Sync would be asked to link a directory onto itself, and the
        // mapper refuses to load the result — better to hear it now.
        $process = BinSkillsRunner::run(
            Path::create($this->tmp),
            'init --target=.claude/skills --alias=.claude/skills',
        );

        Assert::same($process->getExitCode(), 2, 'an invalid CLI shape must exit INVALID');
        Assert::false(
            \is_file($this->tmp . '/skills.json'),
            'nothing must be written when the arguments contradict each other',
        );
    }

    public function contradictoryBooleanFlagsAreRefused(): void
    {
        $process = BinSkillsRunner::run(Path::create($this->tmp), 'init --discovery --no-discovery');

        Assert::same($process->getExitCode(), 2);
        Assert::true(
            \str_contains($process->getErrorOutput(), 'contradict'),
            'the user must be told which flags disagree. Got: ' . $process->getErrorOutput(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSkillsJson(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode(
            (string) \file_get_contents($this->tmp . '/skills.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }
}
