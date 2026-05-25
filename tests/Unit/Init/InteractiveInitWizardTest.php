<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Init;

use Composer\IO\BufferIO;
use LLM\Skills\Init\InteractiveInitWizard;
use Testo\Assert;
use Testo\Test;

/**
 * Unit coverage for the `skills:init` interactive wizard.
 *
 * Drives the wizard with Composer's {@see BufferIO} — its
 * `setUserInputs()` helper streams pre-baked answers into the
 * underlying StringInput, so each test reads like a script.
 */
#[Test]
final class InteractiveInitWizardTest
{
    public function acceptingAllDefaultsWithEmptyDefaultsYieldsEmptyResult(): void
    {
        // Empty answer + every-question defaults to "skip" = produces
        // a skills.json with only `$schema`. The wizard returns an
        // empty array; the caller prepends the schema pointer.
        $io = $this->ioWithAnswers([
            '',  // target (default)
            '',  // aliases (default 'none')
            '',  // trusted (default '<none>')
            '',  // trusted-replace (default no)
            '',  // discovery (default no)
            '',  // auto-sync (default no)
            'yes', // confirm write
        ]);

        $result = (new InteractiveInitWizard())->run($io, []);

        Assert::same($result, []);
    }

    public function overridingTargetIsReflectedInResult(): void
    {
        $io = $this->ioWithAnswers([
            'custom/skills', // target
            '',
            '',
            '',
            '',
            '',
            'yes',
        ]);

        $result = (new InteractiveInitWizard())->run($io, []);

        Assert::same($result, ['target' => 'custom/skills']);
    }

    public function aliasesByNumberMapToWellKnownPaths(): void
    {
        // `1,3` → .claude/skills, .agents/skills. Number selection
        // is the easiest UX on Windows where checkboxes are awkward.
        // We override target so the alias #3 (.agents/skills) does
        // NOT collide with the default target; the equality check
        // has its own dedicated test below.
        $io = $this->ioWithAnswers([
            'custom/skills',
            '1,3',
            '',
            '',
            '',
            '',
            'yes',
        ]);

        $result = (new InteractiveInitWizard())->run($io, []);

        Assert::same($result, [
            'target' => 'custom/skills',
            'aliases' => ['.claude/skills', '.agents/skills'],
        ]);
    }

    public function aliasesByRangeExpand(): void
    {
        // `1-3` → all three known aliases. Target offset to avoid
        // the alias-vs-target collision check.
        $io = $this->ioWithAnswers([
            'custom/skills',
            '1-3',
            '',
            '',
            '',
            '',
            'yes',
        ]);

        $result = (new InteractiveInitWizard())->run($io, []);

        Assert::same(
            $result['aliases'] ?? null,
            ['.claude/skills', '.cursor/skills', '.agents/skills'],
        );
    }

    public function aliasesMixNumbersWithCustomPaths(): void
    {
        // Non-numeric tokens are treated as literal paths — lets the
        // user combine the known shortlist with arbitrary entries.
        $io = $this->ioWithAnswers([
            '',
            '1,custom/path,2',
            '',
            '',
            '',
            '',
            'yes',
        ]);

        $result = (new InteractiveInitWizard())->run($io, []);

        Assert::same(
            $result['aliases'] ?? null,
            ['.claude/skills', 'custom/path', '.cursor/skills'],
        );
    }

    public function aliasEqualToTargetIsDroppedWithNotice(): void
    {
        // `.agents/skills` is option #3 AND the default target. The
        // wizard drops it with a notice rather than letting the
        // mapper bomb later.
        $io = $this->ioWithAnswers([
            '',          // target = .agents/skills (default)
            '1,2,3',     // aliases: .claude, .cursor, .agents
            '',
            '',
            '',
            '',
            'yes',
        ]);

        $result = (new InteractiveInitWizard())->run($io, []);

        Assert::same(
            $result['aliases'] ?? null,
            ['.claude/skills', '.cursor/skills'],
            '.agents/skills equals the target and must be dropped',
        );
        Assert::true(
            \str_contains($io->getOutput(), 'dropped'),
            'user must see why the alias was dropped',
        );
    }

    public function noneInputClearsExistingAliases(): void
    {
        // --force on a project that already has aliases: defaults
        // surface them, but the user can wipe the list by typing
        // "none" (or `0`, or just Enter would keep them).
        $io = $this->ioWithAnswers([
            '',
            'none',
            '',
            '',
            '',
            '',
            'yes',
        ]);

        $result = (new InteractiveInitWizard())->run($io, [
            'aliases' => ['.claude/skills', '.cursor/skills'],
        ]);

        Assert::false(
            \array_key_exists('aliases', $result ?? []),
            'empty list must not be written into skills.json',
        );
    }

    public function trustedCsvIsParsedIntoList(): void
    {
        $io = $this->ioWithAnswers([
            '',
            '',
            'acme/*,vendor/pkg',
            '',
            '',
            '',
            'yes',
        ]);

        $result = (new InteractiveInitWizard())->run($io, []);

        Assert::same($result['trusted'] ?? null, ['acme/*', 'vendor/pkg']);
    }

    public function booleanPromptsCapturedCorrectly(): void
    {
        // Only non-default values land in the result map (defaults are
        // implicit). trusted-replace / discovery default to `false` →
        // answering "yes" inverts them and they appear. auto-sync
        // defaults to `true` → answering "no" inverts it and the
        // key appears with `false`.
        $io = $this->ioWithAnswers([
            '',
            '',
            '',
            'yes',  // trusted-replace → non-default true
            'yes',  // discovery       → non-default true
            'no',   // auto-sync       → non-default false
            'yes',  // confirm write
        ]);

        $result = (new InteractiveInitWizard())->run($io, []);

        Assert::same($result['trusted-replace'] ?? null, true);
        Assert::same($result['discovery'] ?? null, true);
        Assert::same($result['auto-sync'] ?? null, false);
    }

    public function autoSyncDefaultIsImplicitInResult(): void
    {
        // Accepting the new default (true) does not produce an
        // `auto-sync` key — the default carries the value.
        $io = $this->ioWithAnswers([
            '', '', '', '', '',
            '',     // auto-sync: accept default `true`
            'yes',
        ]);

        $result = (new InteractiveInitWizard())->run($io, []);

        Assert::false(
            \array_key_exists('auto-sync', $result ?? []),
            'auto-sync=true is the default and must not be echoed',
        );
    }

    public function finalConfirmationNoAbortsCleanly(): void
    {
        // The wizard returns null when the user backs out at the
        // final write prompt. Caller (InitRunner) treats that as
        // SUCCESS without touching the filesystem.
        $io = $this->ioWithAnswers([
            'custom/skills',
            '',
            '',
            '',
            '',
            '',
            'no',
        ]);

        $result = (new InteractiveInitWizard())->run($io, []);

        Assert::null($result);
        Assert::true(\str_contains($io->getOutput(), 'aborted'));
    }

    public function existingDefaultsAreShownInPrompts(): void
    {
        // When defaults carry a value (e.g. from existing skills.json
        // under --force), the prompts surface them so the user can
        // accept verbatim. Empty input therefore keeps the value.
        $io = $this->ioWithAnswers([
            '',     // target → keep custom/skills
            '',     // aliases → keep claude+cursor
            '',     // trusted → keep
            '',
            '',
            '',
            'yes',
        ]);

        $result = (new InteractiveInitWizard())->run($io, [
            'target' => 'custom/skills',
            'aliases' => ['.claude/skills', '.cursor/skills'],
            'trusted' => ['acme/*'],
        ]);

        Assert::same($result['target'] ?? null, 'custom/skills');
        Assert::same($result['aliases'] ?? null, ['.claude/skills', '.cursor/skills']);
        Assert::same($result['trusted'] ?? null, ['acme/*']);
    }

    /**
     * @param list<string> $answers
     */
    private function ioWithAnswers(array $answers): BufferIO
    {
        $io = new BufferIO();
        $io->setUserInputs($answers);

        return $io;
    }
}
