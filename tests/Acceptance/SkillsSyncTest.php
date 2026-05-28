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
 * Acceptance tests for the `composer skills:update` command.
 *
 * The sandbox project (`tests/Sandbox/project`) installs the following stubs:
 *
 * - `acme/skills-basic`    — trusted (project config). Source `.claude/skills`.
 *                            Skills: `greeting/`, `code-review/`.
 * - `acme/skills-pro`      — trusted. Source `resources/skills`.
 *                            Skills: `refactor/` (with nested `templates/suggestion.md`), `migrate/`.
 * - `acme/skills-broken`   — declares `extra.skills.source` but the value is malformed
 *                            (empty string). Used to exercise graceful skip-with-warning.
 * - `acme/skills-rootlike` — declares `extra.skills` with only root-level options
 *                            (`aliases`, `auto-sync`) and no `source`. Mirrors the
 *                            shape of `llm/skills` itself when seen as a vendor: must
 *                            be skipped silently, not surfaced as malformed.
 * - `clash/skills-conflict`— untrusted. Declares a `greeting` skill that collides
 *                            with `acme/skills-basic` once trusted. Different vendor
 *                            namespace so it is *not* matched by `acme/*` filters.
 * - `evil/payload`         — untrusted. One `tutorial` skill; used for trust-policy tests.
 * - `spiral/skills-demo`   — vendor (`spiral/*`) is in the **built-in** trusted list.
 *                            Used to verify built-in trust and `trusted-replace: true`
 *                            behaviour. One `demo` skill.
 *
 * Sandbox project config: `extra.skills.trusted = ["acme/skills-basic", "acme/skills-pro"]`
 * with the default `trusted-replace: false` — so built-in patterns still apply.
 */
#[Test]
final class SkillsSyncTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';
    private const ALIAS_CLAUDE = Info::PROJECT_DIR . '/.claude/skills-alias';
    private const ALIAS_CURSOR = Info::PROJECT_DIR . '/.cursor/skills-alias';

    /**
     * Wipe the synced skills directory before each test so assertions reflect
     * what this run produced, not leftovers from previous runs. Also snapshot
     * composer.json / skills.json so the migration that `skills:update` now
     * performs (moving inline extra.skills into skills.json) does not leak
     * into the next test.
     */
    #[BeforeTest]
    public function clearTargetDir(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        Filesystem::removeRecursive(Info::PROJECT_DIR . '/custom-skills-target');
        Filesystem::removeRecursive(Info::PROJECT_DIR . '/config-target');
        Filesystem::removeRecursive(self::ALIAS_CLAUDE);
        Filesystem::removeRecursive(self::ALIAS_CURSOR);
        SandboxStateGuard::snapshot();
    }

    #[AfterTest]
    public function restoreSandbox(): void
    {
        SandboxStateGuard::restore();
    }

    // ── basic happy path ────────────────────────────────────────────────────

    public function exitsWithSuccessStatus(): void
    {
        $process = $this->runSync();

        Assert::same(
            $process->getExitCode(),
            0,
            'skills:update must exit with status 0; stderr was: ' . $process->getErrorOutput(),
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

    public function autoDiscoverySyncsProjectTrustedAndBuiltinTrustedSkills(): void
    {
        // internal/path declares no extra.skills; acme/skills-broken has malformed
        // extra; clash/skills-conflict and evil/payload are untrusted. The default
        // sync yields: the four skills from acme/* (project trust) plus the one
        // skill from spiral/skills-demo (built-in `spiral/*` trust).
        $this->runSync();

        $entries = $this->listTargetEntries();

        Assert::same($entries, ['code-review', 'demo', 'greeting', 'migrate', 'refactor']);
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

    public function namingAnUntrustedVendorImplicitlyTrustsItAndSyncsSilently(): void
    {
        // Naming a package as a positional arg is an implicit grant of
        // trust — no warning, no prompt, just sync.
        $process = $this->runSync('evil/payload');

        Assert::same($process->getExitCode(), 0);
        Assert::true(\is_file(self::TARGET_DIR . '/tutorial/SKILL.md'));
        Assert::false(
            \str_contains($process->getErrorOutput(), 'is not trusted'),
            'no "untrusted" warning expected for an explicitly named package. Got: '
            . $process->getErrorOutput(),
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
        // acme/* hits basic + pro (declared), undeclared (auto-discovered
        // because the user named it via wildcard), and broken (malformed
        // extra → -v warning, skipped). It does NOT match clash/skills-conflict
        // (different vendor).
        $process = $this->runSync('acme/*');

        Assert::same($process->getExitCode(), 0);
        Assert::same(
            $this->listTargetEntries(),
            ['auto-skill', 'code-review', 'greeting', 'migrate', 'refactor'],
        );
    }

    public function positionalArgMatchingNoInstalledPackageExitsWithInvalid(): void
    {
        // A typo in the package name should be loud, not silently succeed
        // with zero copies. Exit code 2 (`Command::INVALID`) signals "you
        // asked for something we couldn't find".
        $process = $this->runSync('ghost/package');

        Assert::same($process->getExitCode(), 2);
        Assert::true(
            \str_contains($process->getErrorOutput(), 'ghost/package'),
            'error message should name the offending pattern. Got: ' . $process->getErrorOutput(),
        );
        Assert::false(\is_dir(self::TARGET_DIR), 'nothing must be written when the filter is invalid');
    }

    // ── --target override ───────────────────────────────────────────────────

    #[WithSandboxExtras([
        'target' => 'config-target/skills',
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function customTargetFromProjectConfigWritesToConfiguredLocation(): void
    {
        $configured = Info::PROJECT_DIR . '/config-target/skills';

        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0);
        Assert::true(
            \is_file($configured . '/greeting/SKILL.md'),
            'extra.skills.target from composer.json must redirect output. stderr: '
            . $process->getErrorOutput(),
        );
        // Default target left untouched.
        Assert::false(\is_dir(self::TARGET_DIR));
    }

    #[WithSandboxExtras([
        'target' => 'config-target/skills',
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function cliTargetOverrideBeatsProjectConfigTarget(): void
    {
        // Project says config-target/skills; CLI says custom-skills-target.
        // CLI wins.
        $custom = Info::PROJECT_DIR . '/custom-skills-target';

        $process = $this->runSync('--target=custom-skills-target');

        Assert::same($process->getExitCode(), 0);
        Assert::true(\is_file($custom . '/greeting/SKILL.md'));
        Assert::false(\is_dir(Info::PROJECT_DIR . '/config-target'), 'project config target must be ignored');
    }

    #[WithSandboxExtras(['trusted' => ['evil/*']])]
    public function wildcardPatternInProjectTrustedAllowsThatVendor(): void
    {
        // `extra.skills.trusted: ["evil/*"]` is the project's only explicit
        // trust statement. Implicit trust is still active (default
        // `trusted-replace: false`), so we additionally pick up:
        //
        // - acme/skills-basic / acme/skills-pro via direct-dep trust
        //   (declared under the sandbox's root `require`),
        // - spiral/skills-demo via direct-dep trust AND the built-in
        //   `spiral/*` pattern,
        // - evil/payload via the project wildcard (it is a *transitive*
        //   dep through transit/untrusted-relay, so direct-dep trust
        //   does not cover it — the wildcard is what unlocks it).
        //
        // clash/skills-conflict stays skipped: same transitive position,
        // no pattern matches it.
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0);
        Assert::same(
            $this->listTargetEntries(),
            ['code-review', 'demo', 'greeting', 'migrate', 'refactor', 'tutorial'],
            'project wildcard unlocks evil/payload; direct-dep trust covers the rest. stderr: '
            . $process->getErrorOutput(),
        );
    }

    // ── direct-dep trust ────────────────────────────────────────────────────

    #[WithSandboxExtras(['trusted' => []])]
    public function directDependencyIsImplicitlyTrustedWithoutAnyExplicitPattern(): void
    {
        // Project trust list is empty and the built-in list does not
        // cover `acme/*`. The donors still ship because they are
        // declared under the sandbox's root `require` — direct-dep
        // trust is the implicit grant.
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0);
        Assert::true(
            \is_file(self::TARGET_DIR . '/greeting/SKILL.md'),
            'acme/skills-basic (direct dep, not in any trust list) must be synced. stderr: '
            . $process->getErrorOutput(),
        );
        Assert::true(
            \is_file(self::TARGET_DIR . '/refactor/SKILL.md'),
            'acme/skills-pro (direct dep, not in any trust list) must be synced',
        );
    }

    public function transitiveDependencyStaysSkippedWithoutTrustPattern(): void
    {
        // evil/payload reaches the sandbox transitively through
        // transit/untrusted-relay; the consumer never declared it
        // directly, so direct-dep trust does not cover it. With no
        // matching pattern it stays in the untrusted bucket.
        $process = $this->runSync();

        Assert::false(
            \is_file(self::TARGET_DIR . '/tutorial/SKILL.md'),
            'transitive dep without trust must not be synced',
        );
        Assert::true(
            \str_contains($process->getErrorOutput(), 'evil/payload'),
            'untrusted transitive dep should be named in the skip notice. stderr: '
            . $process->getErrorOutput(),
        );
    }

    #[WithSandboxExtras(['trusted' => [], 'trusted-replace' => true])]
    public function trustedReplaceTrueAlsoDisablesDirectDependencyTrust(): void
    {
        // `trusted-replace: true` is the opt-out for every implicit
        // trust source — built-in *and* direct-dep. With an empty
        // project list the effective trust becomes empty, so even
        // root-declared donors get skipped.
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0);
        Assert::same($this->listTargetEntries(), []);
    }

    // ── trusted-replace ─────────────────────────────────────────────────────

    #[WithSandboxExtras(['trusted' => []])]
    public function builtinTrustedListIsActiveWhenReplaceIsFalse(): void
    {
        // Project trust is explicitly empty. With the default
        // `trusted-replace: false`, both implicit lists remain active:
        // the built-in list approves spiral/skills-demo, and direct-dep
        // trust approves every donor declared under the sandbox's root
        // `require` (acme/skills-basic, acme/skills-pro and again
        // spiral/skills-demo). The transitive evil/payload and
        // clash/skills-conflict have no pattern coverage and stay
        // skipped.
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0);
        Assert::same(
            $this->listTargetEntries(),
            ['code-review', 'demo', 'greeting', 'migrate', 'refactor'],
        );
    }

    #[WithSandboxExtras(['trusted' => [], 'trusted-replace' => true])]
    public function trustedReplaceTrueDisablesBuiltinList(): void
    {
        // With both `trusted: []` and `trusted-replace: true` the effective
        // trust list is empty: even built-in-matched donors must be
        // dropped. Target is left empty (or never created).
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0);
        Assert::same($this->listTargetEntries(), []);
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic'],
        'trusted-replace' => true,
    ])]
    public function trustedReplaceTrueLimitsTrustToProjectListExactly(): void
    {
        // `trusted-replace: true` + project trusts only `acme/skills-basic`.
        // spiral/skills-demo must NOT appear (built-in bypassed); other
        // acme/* must NOT appear (not in project list); only basic's two
        // skills end up in the target.
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0);
        Assert::same($this->listTargetEntries(), ['code-review', 'greeting']);
    }

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

    // ── --dry-run ───────────────────────────────────────────────────────────

    public function dryRunFlagPreventsAnyFilesystemWrites(): void
    {
        $process = $this->runSync('--dry-run');

        Assert::same($process->getExitCode(), 0);
        // Target dir must not exist (BeforeTest wipes it; dry-run must not recreate).
        Assert::false(\is_dir(self::TARGET_DIR));
    }

    public function dryRunOutputAdvertisesItselfAndUsesWouldCopyVerb(): void
    {
        $process = $this->runSync('--dry-run');
        $stdout = $process->getOutput();

        Assert::true(
            \str_contains($stdout, 'dry-run'),
            'dry-run banner must appear in output. Got: ' . $stdout,
        );
        Assert::true(
            \str_contains($stdout, '[would copy]'),
            'per-skill line should use the "would copy" verb. Got: ' . $stdout,
        );
        Assert::true(
            \str_contains($stdout, 'would sync'),
            'summary line should use the "would sync" verb. Got: ' . $stdout,
        );
    }

    public function dryRunReportsConflictsWithoutWritingAnything(): void
    {
        // Same setup as the regular conflict test, plus --dry-run. Outcome
        // must be identical: non-zero exit + nothing on disk.
        $process = $this->runSync('--trust=clash/skills-conflict', '--dry-run');

        Assert::notSame($process->getExitCode(), 0);
        Assert::false(\is_dir(self::TARGET_DIR));
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

    public function vendorWithRootLevelSkillsConfigButNoSourceIsSkippedSilently(): void
    {
        // acme/skills-rootlike declares extra.skills with only `aliases` and
        // `auto-sync` (root-level keys) and no `source`. It is not opting in
        // as a donor, so even -v must not warn about it — silent skip.
        // This is the same shape `llm/skills` itself has when installed as a
        // vendor dependency.
        $process = $this->runSync('-v');
        $combined = $process->getOutput() . $process->getErrorOutput();

        Assert::same($process->getExitCode(), 0);
        Assert::false(
            \str_contains($combined, 'acme/skills-rootlike'),
            'package without extra.skills.source must not appear anywhere in sync output. '
            . 'Got: ' . $combined,
        );
    }

    // ── --discovery ─────────────────────────────────────────────────────────

    public function withoutDiscoveryFlagUndeclaredSkillsAreNotSynced(): void
    {
        // acme/skills-undeclared ships a skills/auto-skill but no extra.skills.
        // Without --discovery, the auto-skill must NOT be copied.
        $this->runSync();

        Assert::false(
            \is_file(self::TARGET_DIR . '/auto-skill/SKILL.md'),
            'auto-skill must not appear without --discovery',
        );
    }

    public function withoutDiscoveryFlagOutputIncludesHintWhenCandidatesExist(): void
    {
        $process = $this->runSync();
        $combined = $process->getOutput() . $process->getErrorOutput();

        Assert::true(
            \str_contains($combined, '--discovery'),
            'output must hint about --discovery when undeclared candidates exist. Got: ' . $combined,
        );
    }

    public function discoveryFlagIncludesUndeclaredSkillsFromTrustedVendor(): void
    {
        // acme/* is not blanket-trusted in the sandbox; the project trust only
        // covers basic and pro by exact name. We use --trust to whitelist the
        // discovered package explicitly so it survives the trust filter.
        $process = $this->runSync('--discovery', '--trust=acme/skills-undeclared');

        Assert::same($process->getExitCode(), 0);
        Assert::true(
            \is_file(self::TARGET_DIR . '/auto-skill/SKILL.md'),
            'auto-skill must be synced under --discovery + trust. stderr: ' . $process->getErrorOutput(),
        );
    }

    public function discoveryFlagDoesNotEmitTheHint(): void
    {
        // Once --discovery is on, the runner has nothing to hint about.
        $process = $this->runSync('--discovery');
        $combined = $process->getOutput() . $process->getErrorOutput();

        Assert::false(
            \str_contains($combined, '[hint]'),
            'hint must not appear when --discovery is active. Got: ' . $combined,
        );
    }

    public function namingAnUndeclaredPackageAutoEnablesDiscoveryForItOnly(): void
    {
        // Naming acme/skills-undeclared as a positional arg should pull in
        // its skills/ root via auto-discovery, *without* enabling discovery
        // globally. evil/payload also has no extra.skills but was not named
        // — it must not be auto-discovered.
        $process = $this->runSync('acme/skills-undeclared');

        Assert::same($process->getExitCode(), 0);
        Assert::true(
            \is_file(self::TARGET_DIR . '/auto-skill/SKILL.md'),
            'named undeclared package must be auto-discovered. stderr: ' . $process->getErrorOutput(),
        );
    }

    public function namingAVendorWildcardAutoDiscoversAllUndeclaredPackagesUnderIt(): void
    {
        // `acme/*` covers both declared (skills-basic, skills-pro) and
        // undeclared (skills-undeclared) packages — all should ship.
        $process = $this->runSync('acme/*');

        Assert::same($process->getExitCode(), 0);
        Assert::true(
            \is_file(self::TARGET_DIR . '/auto-skill/SKILL.md'),
            'wildcard named undeclared package must be auto-discovered. stderr: '
            . $process->getErrorOutput(),
        );
        Assert::true(\is_file(self::TARGET_DIR . '/greeting/SKILL.md'));
    }

    // ── aliases ─────────────────────────────────────────────────────────────

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
        'aliases' => ['.claude/skills-alias'],
    ])]
    public function aliasConfiguredInProjectIsCreatedAsLinkPointingAtTarget(): void
    {
        $process = $this->runSync();

        Assert::same(
            $process->getExitCode(),
            0,
            'sync must succeed with an alias configured. stderr: ' . $process->getErrorOutput(),
        );
        Assert::true(
            \file_exists(self::ALIAS_CLAUDE),
            'alias path must exist after sync. stderr: ' . $process->getErrorOutput(),
        );
        Assert::true(
            $this->aliasResolvesToTarget(self::ALIAS_CLAUDE),
            'alias must resolve to the configured target (.agents/skills)',
        );
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
        'aliases' => ['.claude/skills-alias'],
    ])]
    public function aliasContentsMatchTarget(): void
    {
        // Behavioural proof that the link is real: skill files copied
        // into the target must be visible through the alias path.
        $this->runSync();

        Assert::true(\is_file(self::ALIAS_CLAUDE . '/greeting/SKILL.md'));
        Assert::true(\is_file(self::ALIAS_CLAUDE . '/refactor/SKILL.md'));
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
        'aliases' => ['.claude/skills-alias', '.cursor/skills-alias'],
    ])]
    public function multipleAliasesAreAllCreated(): void
    {
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true($this->aliasResolvesToTarget(self::ALIAS_CLAUDE));
        Assert::true($this->aliasResolvesToTarget(self::ALIAS_CURSOR));
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
        'aliases' => ['.claude/skills-alias'],
    ])]
    public function aliasCreationIsIdempotent(): void
    {
        // A second run must accept the existing link as already-correct
        // and exit cleanly. The state-matrix's "link → target → noop"
        // path is what makes routine re-runs safe.
        $first = $this->runSync();
        $second = $this->runSync();

        Assert::same($first->getExitCode(), 0, 'first run failed: ' . $first->getErrorOutput());
        Assert::same($second->getExitCode(), 0, 'second run failed: ' . $second->getErrorOutput());
        Assert::true($this->aliasResolvesToTarget(self::ALIAS_CLAUDE));
    }

    public function cliAliasFlagReplacesProjectConfigAliases(): void
    {
        // Default sandbox has no aliases; pass one via `--alias` to prove
        // the CLI surface works. Plain sync without --alias has nothing
        // at the alias path, but with the flag the link materialises.
        $process = $this->runSync('--alias=.claude/skills-alias');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            $this->aliasResolvesToTarget(self::ALIAS_CLAUDE),
            '--alias must create the link. stderr: ' . $process->getErrorOutput(),
        );
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
        'aliases' => ['.claude/skills-alias'],
    ])]
    public function cliAliasFlagReplacesProjectAliasesEntirely(): void
    {
        // Project config has `.claude/skills-alias`, CLI passes only
        // `.cursor/skills-alias`. CLI `--alias` is a takeover, not a
        // merge: only the cursor alias is created — the claude one
        // is NOT inherited from project config.
        $process = $this->runSync('--alias=.cursor/skills-alias');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true($this->aliasResolvesToTarget(self::ALIAS_CURSOR));
        Assert::false(
            \file_exists(self::ALIAS_CLAUDE),
            'project alias must not be created when CLI --alias takes over',
        );
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
        'aliases' => ['.claude/skills-alias'],
    ])]
    public function aliasOutputLineIsEmittedAfterCopyReport(): void
    {
        $process = $this->runSync();

        Assert::true(
            \str_contains($process->getOutput(), '[link]'),
            '[link] line must appear in stdout. Got: ' . $process->getOutput(),
        );
        Assert::true(
            \str_contains($process->getOutput(), '.claude/skills-alias')
            || \str_contains($process->getOutput(), '.claude\\skills-alias'),
            '[link] line must name the alias path. Got: ' . $process->getOutput(),
        );
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
        'aliases' => ['.claude/skills-alias'],
    ])]
    public function preExistingRealDirectoryAtAliasPathFailsTheRun(): void
    {
        // The linker never destroys user-owned content. A real
        // directory at the alias path is a fatal misconfiguration the
        // user must resolve before any link can be created. Exit code
        // is non-zero so CI catches it.
        \mkdir(self::ALIAS_CLAUDE, 0o777, true);
        \file_put_contents(self::ALIAS_CLAUDE . '/user-file.txt', 'precious user content');

        $process = $this->runSync();

        Assert::notSame(
            $process->getExitCode(),
            0,
            'real directory at alias path must fail the run',
        );
        Assert::true(
            \str_contains($process->getErrorOutput(), 'link-failed')
            || \str_contains($process->getErrorOutput(), 'real directory'),
            'stderr must explain the alias failure. Got: ' . $process->getErrorOutput(),
        );
        // User content is left untouched — the linker refuses to wipe.
        Assert::true(\is_file(self::ALIAS_CLAUDE . '/user-file.txt'));
        Assert::same(\file_get_contents(self::ALIAS_CLAUDE . '/user-file.txt'), 'precious user content');
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
        'aliases' => ['.agents/skills'],
    ])]
    public function aliasEqualToTargetIsRejectedByConfig(): void
    {
        // Mapper-level lexical validation: the alias and the target
        // point at the same location. Fail loudly, do not invoke the
        // linker at all.
        $process = $this->runSync();

        Assert::notSame($process->getExitCode(), 0);
        Assert::true(
            \str_contains($process->getErrorOutput(), 'extra.skills.aliases')
            || \str_contains($process->getErrorOutput(), 'alias'),
            'stderr must mention the alias config error. Got: ' . $process->getErrorOutput(),
        );
    }

    public function targetEscapingProjectRootIsRejected(): void
    {
        // Containment guard: a CLI `--target=../escape` (or anything
        // that resolves outside the project tree) must fail loudly,
        // never start writing files into a parent directory.
        $process = $this->runSync('--target=../escape');

        Assert::notSame($process->getExitCode(), 0);
        Assert::true(
            \str_contains($process->getErrorOutput(), 'outside the project root'),
            'stderr must explain the containment failure. Got: ' . $process->getErrorOutput(),
        );
        Assert::false(
            \is_dir(Info::PROJECT_DIR . '/../escape'),
            'no directory must be created outside the project root',
        );
    }

    public function aliasEscapingProjectRootIsRejected(): void
    {
        // Same guard for `--alias`: a junction at `../escape` would
        // expose an arbitrary parent location through the project
        // tree. Reject before any junction is created.
        $process = $this->runSync('--alias=../escape');

        Assert::notSame($process->getExitCode(), 0);
        Assert::true(
            \str_contains($process->getErrorOutput(), 'outside the project root'),
            'stderr must explain the containment failure. Got: ' . $process->getErrorOutput(),
        );
        Assert::false(
            \file_exists(Info::PROJECT_DIR . '/../escape'),
            'no junction must be created outside the project root',
        );
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
        'aliases' => ['.claude/skills-alias'],
    ])]
    public function dryRunDoesNotCreateAliasOnDisk(): void
    {
        $process = $this->runSync('--dry-run');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::false(
            \file_exists(self::ALIAS_CLAUDE),
            'dry-run must not create the alias',
        );
        Assert::true(
            \str_contains($process->getOutput(), '[would link]'),
            'dry-run must announce the would-be link. Got: ' . $process->getOutput(),
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

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

    /**
     * Behavioural check: does `$aliasPath` resolve, on disk, to the same
     * canonical path as the sandbox's default target dir? Used in lieu
     * of `is_link` because PHP can't reliably detect NTFS junctions —
     * the realpath comparison works across symlinks, junctions and
     * platform separators.
     */
    private function aliasResolvesToTarget(string $aliasPath): bool
    {
        $resolvedAlias = \realpath($aliasPath);
        $resolvedTarget = \realpath(self::TARGET_DIR);
        if ($resolvedAlias === false || $resolvedTarget === false) {
            return false;
        }

        return \DIRECTORY_SEPARATOR === '\\'
            ? \strcasecmp($resolvedAlias, $resolvedTarget) === 0
            : $resolvedAlias === $resolvedTarget;
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
