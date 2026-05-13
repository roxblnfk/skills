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
 * The sandbox project (`tests/Sandbox/project`) installs the following stubs:
 *
 * - `acme/skills-basic`    — trusted (project config). Source `.claude/skills`.
 *                            Skills: `greeting/`, `code-review/`.
 * - `acme/skills-pro`      — trusted. Source `resources/skills`.
 *                            Skills: `refactor/` (with nested `templates/suggestion.md`), `migrate/`.
 * - `acme/skills-broken`   — declares `extra.skills` but the block is malformed
 *                            (missing `source`). Used to exercise graceful skip-with-warning.
 * - `clash/skills-conflict`— untrusted. Declares a `greeting` skill that collides
 *                            with `acme/skills-basic` once trusted. Different vendor
 *                            namespace so it is *not* matched by `acme/*` filters.
 * - `evil/payload`         — untrusted. One `tutorial` skill; used for trust-policy tests.
 *
 * Sandbox project config: `extra.skills.trusted = ["acme/skills-basic", "acme/skills-pro"]`.
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
        Filesystem::removeRecursive(Info::PROJECT_DIR . '/custom-skills-target');
    }

    // ── basic happy path ────────────────────────────────────────────────────

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

        Assert::true(\is_file(self::TARGET_DIR . '/greeting/SKILL.md'));
        Assert::true(\is_file(self::TARGET_DIR . '/code-review/SKILL.md'));
    }

    public function copiesSkillsFromCustomSourceDirectory(): void
    {
        $this->runSync();

        Assert::true(\is_file(self::TARGET_DIR . '/refactor/SKILL.md'));
        Assert::true(\is_file(self::TARGET_DIR . '/migrate/SKILL.md'));
    }

    public function copiesNestedFilesInsideASkill(): void
    {
        $this->runSync();

        Assert::true(\is_file(self::TARGET_DIR . '/refactor/templates/suggestion.md'));
    }

    public function preservesFileContents(): void
    {
        $this->runSync();

        $source = Info::PACKAGES_DIR . '/acme/skills-basic/.claude/skills/greeting/SKILL.md';
        $target = self::TARGET_DIR . '/greeting/SKILL.md';

        Assert::same(
            \file_get_contents($target),
            \file_get_contents($source),
        );
    }

    public function isIdempotent(): void
    {
        $first = $this->runSync();
        $second = $this->runSync();

        Assert::same($first->getExitCode(), 0);
        Assert::same($second->getExitCode(), 0);
        Assert::true(\is_file(self::TARGET_DIR . '/greeting/SKILL.md'));
    }

    public function autoDiscoverySyncsOnlyTheFourTrustedSkills(): void
    {
        // internal/path declares no extra.skills; acme/skills-broken has
        // malformed extra; clash/skills-conflict and evil/payload are untrusted.
        // Default sync should yield exactly the four trusted skills.
        $this->runSync();

        $entries = $this->listTargetEntries();

        Assert::same($entries, ['code-review', 'greeting', 'migrate', 'refactor']);
    }

    // ── trust policy ────────────────────────────────────────────────────────

    public function untrustedVendorIsSkippedByDefault(): void
    {
        $this->runSync();

        Assert::false(\is_file(self::TARGET_DIR . '/tutorial/SKILL.md'));
    }

    public function trustFlagAllowsUntrustedVendor(): void
    {
        $process = $this->runSync('--trust=evil/payload');

        Assert::same($process->getExitCode(), 0);
        Assert::true(
            \is_file(self::TARGET_DIR . '/tutorial/SKILL.md'),
            'evil/payload skill must be synced when --trust enables it',
        );
    }

    public function trustFlagWithVendorWildcardAllowsAllPackagesUnderThatVendor(): void
    {
        // `evil/*` is a wildcard pattern; it must end-to-end approve every
        // installed package under that vendor namespace. Sandbox installs
        // exactly one such package (`evil/payload`), so the skill ships.
        $process = $this->runSync('--trust=evil/*');

        Assert::same($process->getExitCode(), 0);
        Assert::true(
            \is_file(self::TARGET_DIR . '/tutorial/SKILL.md'),
            'wildcard pattern in --trust must match packages under that vendor. stderr: '
            . $process->getErrorOutput(),
        );
    }

    public function untrustedVendorNamedNonInteractiveSyncsWithWarning(): void
    {
        // Composer is invoked with --no-interaction; spec says: warn + sync.
        $process = $this->runSync('evil/payload');

        Assert::same($process->getExitCode(), 0);
        Assert::true(\is_file(self::TARGET_DIR . '/tutorial/SKILL.md'));
        Assert::true(
            \str_contains($process->getErrorOutput(), 'evil/payload'),
            'stderr should mention the untrusted package name. Got: ' . $process->getErrorOutput(),
        );
    }

    public function untrustedAutoDiscoveredVendorIsMentionedInOutput(): void
    {
        // The skip is silent in the filesystem but the user should still see
        // a one-line notice telling them how to opt in.
        $process = $this->runSync();

        Assert::true(
            \str_contains($process->getErrorOutput(), 'evil/payload')
            || \str_contains($process->getOutput(), 'evil/payload'),
            'evil/payload skip notice must appear in command output',
        );
    }

    // ── positional package filters ──────────────────────────────────────────

    public function positionalArgRestrictsSyncToNamedPackage(): void
    {
        $process = $this->runSync('acme/skills-basic');

        Assert::same($process->getExitCode(), 0);
        Assert::same($this->listTargetEntries(), ['code-review', 'greeting']);
    }

    public function multiplePositionalArgsIncludeAllNamedPackages(): void
    {
        $process = $this->runSync('acme/skills-basic', 'acme/skills-pro');

        Assert::same($process->getExitCode(), 0);
        Assert::same($this->listTargetEntries(), ['code-review', 'greeting', 'migrate', 'refactor']);
    }

    public function wildcardPositionalArgMatchesVendor(): void
    {
        // acme/* hits basic + pro (trusted) + broken (malformed extra → -v warning, skipped).
        // It does NOT match clash/skills-conflict (different vendor).
        $process = $this->runSync('acme/*');

        Assert::same($process->getExitCode(), 0);
        Assert::same($this->listTargetEntries(), ['code-review', 'greeting', 'migrate', 'refactor']);
    }

    // ── --target override ───────────────────────────────────────────────────

    public function customTargetFlagWritesToOverridePath(): void
    {
        $custom = Info::PROJECT_DIR . '/custom-skills-target';

        $process = $this->runSync('--target=custom-skills-target');

        Assert::same($process->getExitCode(), 0);
        Assert::true(\is_file($custom . '/greeting/SKILL.md'));
        Assert::true(\is_file($custom . '/refactor/SKILL.md'));
        // Default target left untouched because we redirected.
        Assert::false(\is_file(self::TARGET_DIR . '/greeting/SKILL.md'));
    }

    // ── conflicts ───────────────────────────────────────────────────────────

    public function conflictBetweenTrustedDonorsAbortsSyncAndWritesNothing(): void
    {
        // clash/skills-conflict declares "greeting" — same as acme/skills-basic.
        // Trusting clash via --trust pulls both into the approved list and
        // the engine must refuse to pick a winner.
        $process = $this->runSync('--trust=clash/skills-conflict');

        Assert::notSame($process->getExitCode(), 0, 'conflict must produce a non-zero exit');
        Assert::true(
            \str_contains($process->getErrorOutput(), 'conflict')
            || \str_contains($process->getErrorOutput(), 'greeting'),
            'stderr should explain the conflict. Got: ' . $process->getErrorOutput(),
        );
        Assert::false(
            \is_file(self::TARGET_DIR . '/greeting/SKILL.md'),
            'nothing must be written when a conflict is detected',
        );
        Assert::false(
            \is_file(self::TARGET_DIR . '/refactor/SKILL.md'),
            'unrelated skills must not be written either — the engine is transactional',
        );
    }

    // ── malformed vendor extras ─────────────────────────────────────────────

    public function malformedVendorExtraDoesNotBlockOtherDonors(): void
    {
        // acme/skills-broken is always installed in the sandbox; this exercises
        // the same path as the happy tests but the assertion makes the intent
        // explicit: a broken donor never aborts sync.
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0);
        Assert::true(\is_file(self::TARGET_DIR . '/greeting/SKILL.md'));
        Assert::true(\is_file(self::TARGET_DIR . '/refactor/SKILL.md'));
    }

    public function verboseFlagSurfacesMalformedVendorWarning(): void
    {
        $process = $this->runSync('-v');

        Assert::same($process->getExitCode(), 0);
        Assert::true(
            \str_contains($process->getErrorOutput(), 'acme/skills-broken'),
            '-v output should mention the malformed donor. Got: ' . $process->getErrorOutput(),
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function runSync(string ...$args): Process
    {
        $command = 'skills:sync';
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
     * @return list<string> sorted list of immediate entries under {@see self::TARGET_DIR}
     */
    private function listTargetEntries(): array
    {
        if (!\is_dir(self::TARGET_DIR)) {
            return [];
        }

        $entries = \array_values(\array_diff(\scandir(self::TARGET_DIR) ?: [], ['.', '..']));
        \sort($entries);

        return $entries;
    }
}
