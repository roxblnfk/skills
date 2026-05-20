<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Composer\WithSandboxExtras;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance tests for the `composer skills:show` command.
 *
 * Each test starts from a clean target directory so assertions about
 * `[x]` / `[ ]` / `[~]` markers reflect what the test set up, not
 * leftovers from a sibling. Some tests pre-run `skills:update` to
 * exercise the synced path; others read the output before any sync
 * has happened.
 */
#[Test]
final class SkillsShowTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';

    #[BeforeTest]
    public function clearTargetDir(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        Filesystem::removeRecursive(Info::PROJECT_DIR . '/custom-skills-target');
    }

    // ── basics ──────────────────────────────────────────────────────────────

    public function exitsWithSuccessStatus(): void
    {
        $process = $this->runShow();

        Assert::same(
            $process->getExitCode(),
            0,
            'skills:show must exit with status 0; stderr was: ' . $process->getErrorOutput(),
        );
    }

    public function neverWritesToTheTargetDirectory(): void
    {
        $this->runShow();

        Assert::false(
            \is_dir(self::TARGET_DIR),
            'show is read-only and must not create the target directory',
        );
    }

    public function rendersTargetHeaderAtTheTopOfTheOutput(): void
    {
        // The target path lives in a one-line header above the donor
        // groups, since the per-row second column is now the description.
        $process = $this->runShow();
        $lines = \explode("\n", $process->getOutput());

        Assert::true(
            \str_starts_with($lines[0], 'Target:'),
            'first line must be Target header. Got: ' . $lines[0],
        );
        Assert::true(\str_contains($lines[0], '.agents/skills'));
    }

    public function rendersSkillDescriptionFromFrontmatterAsTheSecondColumn(): void
    {
        // The sandbox stubs ship SKILL.md frontmatter; show pulls the
        // `description:` field into the row.
        $process = $this->runShow();
        $out = $process->getOutput();

        Assert::true(
            \str_contains($out, 'Reply with a friendly greeting'),
            'greeting description must appear next to its row. stdout: ' . $out,
        );
        Assert::true(\str_contains($out, 'Review a small diff for obvious issues'));
        Assert::true(\str_contains($out, 'Suggest small, behaviour-preserving refactors'));
    }

    public function listsTrustedDonorsGroupedByVendor(): void
    {
        $process = $this->runShow();
        $out = $process->getOutput();

        // Trusted via project (.composer.json extra.skills.trusted).
        Assert::true(\str_contains($out, 'acme/'));
        Assert::true(\str_contains($out, 'skills-basic'));
        Assert::true(\str_contains($out, 'skills-pro'));
        // Trusted via built-in list (spiral/*).
        Assert::true(\str_contains($out, 'spiral/'));
        Assert::true(\str_contains($out, 'skills-demo'));
    }

    public function listsEachSkillFromTrustedDonors(): void
    {
        $process = $this->runShow();
        $out = $process->getOutput();

        Assert::true(\str_contains($out, 'greeting'));
        Assert::true(\str_contains($out, 'code-review'));
        Assert::true(\str_contains($out, 'refactor'));
        Assert::true(\str_contains($out, 'migrate'));
        Assert::true(\str_contains($out, 'demo'));
    }

    // ── status markers ──────────────────────────────────────────────────────

    public function marksAllSkillsAsNotSyncedBeforeUpdateHasRun(): void
    {
        // Fresh state — no `.agents/skills` exists yet. Composer is invoked
        // with --no-ansi, so the colour chips are stripped down to their
        // 4-char visible content (" NEW", " OK ", " MOD", " !! ", "SKIP").
        $process = $this->runShow();
        $out = $process->getOutput();

        // Every skill from approved donors should carry the NEW chip.
        Assert::true(\str_contains($out, ' NEW') && \str_contains($out, 'greeting'));
        Assert::true(\str_contains($out, 'code-review'));
        Assert::true(\str_contains($out, 'refactor'));
    }

    public function marksSyncedSkillsWithOkChipAfterUpdate(): void
    {
        $this->runUpdate();

        $process = $this->runShow();
        $out = $process->getOutput();

        // Every previously-NEW skill is now OK.
        Assert::true(\str_contains($out, ' OK '));
        // Lines like " OK   greeting" — chip then 2 spaces then name.
        Assert::true(\str_contains($out, ' OK ') && \str_contains($out, 'greeting'));
        Assert::false(\str_contains($out, ' NEW  greeting'));
    }

    public function flagsLocallyEditedSkillAsDriftedWithModChip(): void
    {
        // Sync, then tamper with one of the synced files. show must catch it.
        $this->runUpdate();
        \file_put_contents(self::TARGET_DIR . '/greeting/SKILL.md', '# Tampered');

        $process = $this->runShow();
        $out = $process->getOutput();

        Assert::true(
            \str_contains($out, ' MOD'),
            'show must mark a modified skill with the MOD chip. stdout: ' . $out,
        );
        Assert::true(\str_contains($out, '(modified)'));
        // Other synced skills are still clean.
        Assert::true(\str_contains($out, ' OK '));
    }

    public function userAddedFilesInsideASyncedSkillAreNotDrift(): void
    {
        // Non-destructive merge promise: user notes alongside SKILL.md must
        // not cause the skill to be flagged as drifted.
        $this->runUpdate();
        \file_put_contents(self::TARGET_DIR . '/greeting/my-notes.md', 'mine');

        $process = $this->runShow();
        $out = $process->getOutput();

        // greeting still OK, no MOD chip anywhere in the output.
        Assert::true(\str_contains($out, ' OK '));
        Assert::false(\str_contains($out, ' MOD'));
    }

    // ── trust annotations ───────────────────────────────────────────────────

    public function annotatesBuiltinTrustedDonor(): void
    {
        // spiral/* matches the built-in list, not project trust.
        $process = $this->runShow();
        $out = $process->getOutput();

        // Pull out the lines around the spiral package.
        $lines = \explode("\n", $out);
        $spiralLine = null;
        foreach ($lines as $line) {
            if (\str_contains($line, 'skills-demo')) {
                $spiralLine = $line;
                break;
            }
        }
        Assert::true($spiralLine !== null);
        \assert($spiralLine !== null);
        Assert::true(
            \str_contains($spiralLine, '[via built-in trust]'),
            'spiral/skills-demo should be annotated as built-in. line: ' . $spiralLine,
        );
    }

    #[WithSandboxExtras(['trusted' => []])]
    public function annotatesDirectDependencyTrustedDonor(): void
    {
        // With an empty project trust list and a built-in list that
        // does not cover `acme/*`, the only thing approving
        // `acme/skills-basic` is the fact that it is declared under
        // the sandbox's root `require`. The show output should credit
        // that source via the `[via direct dependency]` annotation.
        $process = $this->runShow();
        $out = $process->getOutput();

        $lines = \explode("\n", $out);
        $basicLine = null;
        foreach ($lines as $line) {
            if (\str_contains($line, 'skills-basic')) {
                $basicLine = $line;
                break;
            }
        }
        Assert::true($basicLine !== null, 'skills-basic must appear in show output. stdout: ' . $out);
        \assert($basicLine !== null);
        Assert::true(
            \str_contains($basicLine, '[via direct dependency]'),
            'direct-dep-trusted donor should be annotated. line: ' . $basicLine,
        );
    }

    public function doesNotAnnotateProjectTrustedDonors(): void
    {
        $process = $this->runShow();
        $out = $process->getOutput();

        $lines = \explode("\n", $out);
        foreach ($lines as $line) {
            if (\str_contains($line, 'skills-basic') || \str_contains($line, 'skills-pro')) {
                Assert::false(
                    \str_contains($line, '[via built-in trust]'),
                    'project-trusted donor must not carry the built-in annotation: ' . $line,
                );
            }
        }
    }

    // ── skipped section ─────────────────────────────────────────────────────

    public function listsUntrustedDonorsInSkippedSection(): void
    {
        // The chip text itself is the reason — no generic SKIP marker.
        $process = $this->runShow();
        $out = $process->getOutput();

        Assert::true(\str_contains($out, 'Skipped:'));
        Assert::false(\str_contains($out, ' SKIP '));
        Assert::true(\str_contains($out, 'evil/payload'));
        Assert::true(\str_contains($out, ' untrusted '));
    }

    public function listsMalformedDonorWithReasonDetail(): void
    {
        $process = $this->runShow();
        $out = $process->getOutput();

        Assert::true(\str_contains($out, 'acme/skills-broken'));
        Assert::true(\str_contains($out, 'malformed'));
        Assert::true(
            \str_contains($out, 'extra.skills.source'),
            'malformed reason should include the mapper detail. stdout: ' . $out,
        );
    }

    public function packageWithRootLevelSkillsConfigButNoSourceIsNotListedAnywhere(): void
    {
        // acme/skills-rootlike declares extra.skills with only `aliases` and
        // `auto-sync` — root-level options that are meaningful for the root
        // project but not for a vendor donor. Because it sets no `source`,
        // the package is not a donor and must not appear anywhere in show
        // output: not in the main listing, not in `Skipped:` as malformed.
        // This mirrors what `llm/skills` itself looks like when seen as a
        // vendor dependency (issue #10).
        $process = $this->runShow();
        $combined = $process->getOutput() . $process->getErrorOutput();

        Assert::false(
            \str_contains($combined, 'acme/skills-rootlike'),
            'package without extra.skills.source must not appear in show output. '
            . 'Got: ' . $combined,
        );
    }

    public function listsFilteredOutDonorsWhenPositionalPatternIsUsed(): void
    {
        $process = $this->runShow('acme/skills-basic');
        $out = $process->getOutput();

        // acme/skills-basic survives; acme/skills-pro is filtered out.
        Assert::true(\str_contains($out, 'skills-basic'));
        Assert::true(\str_contains($out, 'skills-pro'));
        Assert::true(\str_contains($out, 'filtered-out'));
    }

    // ── --trust ─────────────────────────────────────────────────────────────

    public function trustFlagPromotesDonorIntoMainListing(): void
    {
        $without = $this->runShow();
        $with = $this->runShow('--trust=evil/payload');

        // Without the flag, the `tutorial` skill from evil/payload is not
        // in the main listing at all (the donor sits under `Skipped:`).
        Assert::false(\str_contains($without->getOutput(), 'tutorial '));
        // With the flag, the `tutorial` skill shows up — and since the
        // target was just cleaned, it should be NEW.
        $withOut = $with->getOutput();
        Assert::true(\str_contains($withOut, 'tutorial'));
        Assert::true(\str_contains($withOut, ' NEW'));
    }

    // ── --target ────────────────────────────────────────────────────────────

    public function targetOverrideChangesStatusEvaluation(): void
    {
        // Sync into the default target; then point show at an empty alternate
        // target — all skills should report as not synced relative to that.
        $this->runUpdate();
        $altTarget = Info::PROJECT_DIR . '/custom-skills-target';
        Filesystem::removeRecursive($altTarget);

        $process = $this->runShow('--target=custom-skills-target');
        $out = $process->getOutput();

        // Status against an empty alternate target ⇒ everything is NEW.
        Assert::true(\str_contains($out, ' NEW'));
        Assert::false(\str_contains($out, ' OK '));
    }

    // ── --discovery ─────────────────────────────────────────────────────────

    public function withoutDiscoveryFlagUndeclaredDonorIsAbsentFromMainListing(): void
    {
        $process = $this->runShow();
        $out = $process->getOutput();

        Assert::false(
            \str_contains($out, 'auto-skill'),
            'auto-skill must not appear in show without --discovery. Got: ' . $out,
        );
    }

    public function withoutDiscoveryFlagUndeclaredDonorIsListedInSkippedAsNotDeclared(): void
    {
        // Even though the skill itself is hidden, the package name should
        // still surface under Skipped with reason `not-declared` so the user
        // sees what they would be opting in to.
        $process = $this->runShow();
        $out = $process->getOutput();

        Assert::true(\str_contains($out, 'Skipped:'));
        Assert::true(
            \str_contains($out, 'not-declared'),
            'undeclared donor must carry a not-declared chip. Got: ' . $out,
        );
        Assert::true(
            \str_contains($out, 'acme/skills-undeclared'),
            'undeclared donor package name must be listed. Got: ' . $out,
        );
    }

    public function withoutDiscoveryFlagOutputHintsRerunWithDiscovery(): void
    {
        $process = $this->runShow();
        $out = $process->getOutput();

        Assert::true(
            \str_contains($out, '--discovery'),
            'show must hint about --discovery when undeclared candidates exist. Got: ' . $out,
        );
    }

    public function discoveryFlagPromotesUndeclaredDonorAndMarksItDiscovered(): void
    {
        $process = $this->runShow('--discovery', '--trust=acme/skills-undeclared');
        $out = $process->getOutput();

        Assert::true(
            \str_contains($out, 'skills-undeclared'),
            'discovered donor must appear in main listing. Got: ' . $out,
        );
        Assert::true(
            \str_contains($out, 'auto-skill'),
            'discovered donor skill must appear in listing. Got: ' . $out,
        );
        Assert::true(
            \str_contains($out, '[discovered]'),
            'discovered donor must carry the [discovered] annotation. Got: ' . $out,
        );
    }

    public function discoveryFlagDoesNotEmitTheHint(): void
    {
        $process = $this->runShow('--discovery');
        $out = $process->getOutput();

        Assert::false(
            \str_contains($out, '[hint]'),
            'hint must not appear when --discovery is on. Got: ' . $out,
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function runShow(string ...$args): Process
    {
        $command = 'skills:show';
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

    private function runUpdate(): void
    {
        ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:update',
            timeout: 60,
        );
    }
}
