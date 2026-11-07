<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Sync\SkillConflict;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Composer\WithSkillsJson;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance coverage for the `dir` source adapter — the offline path
 * `skills:update` never had before.
 *
 * The sandbox ships a committed fixture directory at
 * `tests/Sandbox/project/local-skills/` with two bare skills
 * (`dir-hello`, `dir-extra`), and `local-skills-case/` with `Dir-Hello`,
 * which differs from `dir-hello` only by case; `local-skills-unicode/über`
 * and `local-skills-unicode-upper/Über` differ only by Unicode case;
 * `local-skills-eszett/straße` and `local-skills-eszett-ascii/strasse` are
 * equal only under full case folding; `local-skills-hidden/skills/` holds a
 * skill in the hidden `.github/` and one in `ZZZZZZ~9/`, shaped like a Windows
 * short name, next to `visible-skill/`; `local-declared-hidden/` declares
 * `extra.skills.source` and holds a plain hidden `.github/` directory next to
 * `declared-hello/`; `local-declared-multi/` declares the sources `skills` and
 * `missing`, the second of which does not exist; `local-composer-malformed/`
 * declares an absolute `extra.skills.source`, which the mapper rejects. Each test injects a `skills.json` whose
 * `sources[]` declares that directory as a `from: "dir"` donor and runs
 * the real `composer skills:update` / `skills:show` against it — no
 * network, no archive, no cache.
 */
#[Test]
final class SkillsDirSourceTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';

    #[BeforeTest]
    public function clearTargetDir(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function updateCopiesSkillsFromDirSource(): void
    {
        $process = $this->runUpdate();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \is_file(self::TARGET_DIR . '/dir-hello/SKILL.md'),
            'dir source skill must be copied into the target. stderr: ' . $process->getErrorOutput(),
        );
        Assert::true(\is_file(self::TARGET_DIR . '/dir-extra/SKILL.md'));
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills', 'skills' => ['dir-hello']],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function updateWithAllowlistSyncsOnlyTheNamedSkill(): void
    {
        $process = $this->runUpdate();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::TARGET_DIR . '/dir-hello/SKILL.md'));
        Assert::false(
            \is_file(self::TARGET_DIR . '/dir-extra/SKILL.md'),
            'dir-extra is not on the allowlist and must not be copied',
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-composer-malformed'],
        ],
    ])]
    public function aFullCleanKeepsWhatADonorWithAMalformedManifestInstalledBefore(): void
    {
        // `mf-pdf` was installed before the donor's composer.json broke.
        \mkdir(self::TARGET_DIR . '/mf-pdf', 0o777, true);
        \file_put_contents(self::TARGET_DIR . '/mf-pdf/notes.md', 'local notes');

        $process = $this->runUpdate('--clean');

        Assert::notSame($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \str_contains($process->getErrorOutput(), '--clean refused: acme/dir-malformed contributed no skills'),
            'stderr: ' . $process->getErrorOutput(),
        );
        Assert::true(\is_file(self::TARGET_DIR . '/mf-pdf/notes.md'), 'nothing may be deleted');
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills', 'skills' => ['gone']],
        ],
    ])]
    public function aFullCleanKeepsWhatADonorWithAStaleAllowlistInstalledBefore(): void
    {
        // `gone` was installed from this donor earlier; the donor no longer
        // ships it, so wiping the target could not restore it.
        \mkdir(self::TARGET_DIR . '/gone', 0o777, true);
        \file_put_contents(self::TARGET_DIR . '/gone/SKILL.md', "---\nname: gone\n---\nbody\n");

        $process = $this->runUpdate('--clean');

        Assert::notSame($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \str_contains($process->getErrorOutput(), '--clean refused: project/local-skills contributed no skills'),
            'stderr: ' . $process->getErrorOutput(),
        );
        Assert::true(\is_file(self::TARGET_DIR . '/gone/SKILL.md'), 'nothing may be deleted');
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
            ['from' => 'dir', 'path' => './local-skills-case'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function skillNamesDifferingOnlyByCaseConflict(): void
    {
        // `local-skills-case/Dir-Hello` and `local-skills/dir-hello` share one
        // directory on Windows and macOS.
        $process = $this->runUpdate();

        Assert::notSame($process->getExitCode(), 0, 'a case-only clash must abort the sync');
        Assert::true(
            \str_contains(
                $process->getErrorOutput(),
                '[conflict] skill "dir-hello" declared by: project/local-skills, project/local-skills-case (as "Dir-Hello")',
            ),
            'stderr must name the clash and both spellings. Got: ' . $process->getErrorOutput(),
        );
        Assert::false(\is_dir(self::TARGET_DIR . '/dir-hello'), 'nothing is written on a conflict');
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
            ['from' => 'dir', 'path' => './local-skills-case'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function aScopedRunConflictsWithADonorItLeavesOut(): void
    {
        // `dir-hello` is what a full run installs from `local-skills`; the
        // scoped run must not overwrite it with `Dir-Hello`.
        \mkdir(self::TARGET_DIR . '/dir-hello', 0o777, true);
        \file_put_contents(self::TARGET_DIR . '/dir-hello/SKILL.md', 'installed from local-skills');

        $process = $this->runUpdate('project/local-skills-case');

        Assert::notSame($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \str_contains($process->getErrorOutput(), '[conflict] skill "Dir-Hello" declared by: project/local-skills-case, project/local-skills'),
            'stderr: ' . $process->getErrorOutput(),
        );
        Assert::same((string) \file_get_contents(self::TARGET_DIR . '/dir-hello/SKILL.md'), 'installed from local-skills');
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills-unicode'],
            ['from' => 'dir', 'path' => './local-skills-unicode-upper'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function skillNamesDifferingOnlyByUnicodeCaseConflict(): void
    {
        // Without both extensions the non-ASCII names are refused before they can clash.
        if (!SkillConflict::foldsUnicode()) {
            throw new SkipTest('mbstring or intl is not available');
        }

        $process = $this->runUpdate();

        Assert::notSame($process->getExitCode(), 0, 'a Unicode case-only clash must abort the sync');
        Assert::true(
            \str_contains($process->getErrorOutput(), '[conflict]'),
            'stderr must name the clash. Got: ' . $process->getErrorOutput(),
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills-hidden'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function hiddenSkillDirectoriesAreNotInstalled(): void
    {
        // No `-v`: the donor is otherwise fine, so the skip must be visible by default.
        $process = $this->runUpdate();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::TARGET_DIR . '/visible-skill/SKILL.md'), 'stderr: ' . $process->getErrorOutput());
        Assert::false(\file_exists(self::TARGET_DIR . '/.github'), 'a hidden directory must not become a skill');
        Assert::true(
            \str_contains($process->getErrorOutput(), '[skip] project/local-skills-hidden: skill directory ".github" is hidden'),
            'the skipped directory must be explained. Got: ' . $process->getErrorOutput(),
        );
        Assert::false(\file_exists(self::TARGET_DIR . '/ZZZZZZ~9'), 'a short-name directory must not become a skill');
        Assert::true(
            \str_contains($process->getOutput() . $process->getErrorOutput(), '"ZZZZZZ~9" looks like a Windows short name'),
            'the skipped short name must be explained. Got: ' . $process->getErrorOutput(),
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-declared-multi', 'skills' => ['multi-hello', 'typo']],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function showListsOnlyTheMissingSourceAsSourceMissing(): void
    {
        $out = $this->runShow()->getOutput();

        Assert::same(
            \preg_match_all('/source-missing\s+acme\/dir-declared-multi/', $out),
            1,
            'only the missing source directory is a missing source. Got: ' . $out,
        );
        Assert::true(\str_contains($out, 'source directory "missing" does not exist'), 'Got: ' . $out);
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills-hidden'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function showListsSkillsRefusedForTheirDirectoryName(): void
    {
        $out = $this->runShow()->getOutput();

        Assert::true(
            \preg_match('/skill-refused\s+project\/local-skills-hidden\s+skill directory "\.github" is hidden/', $out) === 1,
            'show must list the refused skill as sync reports it. Got: ' . $out,
        );
        Assert::true(\preg_match('/^\s+\S+\s+visible-skill\s/m', $out) === 1, 'Got: ' . $out);
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills-unportable'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function directoryNamesWindowsRewritesAreNotInstalled(): void
    {
        // Built at run time: Windows cannot hold these names, which is the point.
        $root = Info::PROJECT_DIR . '/local-skills-unportable/skills';
        try {
            foreach (['foo.', 'con'] as $name) {
                @\mkdir($root . '/' . $name, 0o777, true);
                @\file_put_contents($root . '/' . $name . '/SKILL.md', "---\nname: unportable\n---\n");
            }
            if (!\in_array('foo.', \scandir($root) ?: [], true)) {
                throw new SkipTest('the file system cannot hold the names under test');
            }

            $process = $this->runUpdate();

            Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
            Assert::false(\file_exists(self::TARGET_DIR . '/foo.') || \file_exists(self::TARGET_DIR . '/foo'));
            Assert::false(\file_exists(self::TARGET_DIR . '/con'));
            Assert::true(
                \str_contains($process->getErrorOutput(), 'skill directory "foo." ends in a dot or space'),
                'Got: ' . $process->getErrorOutput(),
            );
        } finally {
            Filesystem::removeRecursive(Info::PROJECT_DIR . '/local-skills-unportable');
        }
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-declared-hidden'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function hiddenPlainDirectoryInADeclaredSourceIsSkippedQuietly(): void
    {
        $process = $this->runUpdate();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::TARGET_DIR . '/declared-hello/SKILL.md'), 'stderr: ' . $process->getErrorOutput());
        Assert::false(\file_exists(self::TARGET_DIR . '/.github'));
        Assert::false(
            \str_contains($process->getErrorOutput(), '".github"'),
            'a plain directory that is not a skill must not be reported. Got: ' . $process->getErrorOutput(),
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills-case'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function scopedCleanKeepsAnotherSkillOnACaseSensitiveTarget(): void
    {
        // On a case-sensitive target `dir-hello` is another skill's directory,
        // not the one `Dir-Hello` is written to. Windows needs the directory
        // flagged case-sensitive, which not every volume permits.
        \mkdir(self::TARGET_DIR, 0o777, true);
        Filesystem::makeCaseSensitive(self::TARGET_DIR);
        \mkdir(self::TARGET_DIR . '/dir-hello');
        \file_put_contents(self::TARGET_DIR . '/dir-hello/SKILL.md', "---\nname: dir-hello\n---\nother donor\n");
        if (\is_dir(self::TARGET_DIR . '/Dir-Hello')) {
            throw new SkipTest('the target folds case');
        }

        $process = $this->runUpdate('project/local-skills-case', '--clean');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::TARGET_DIR . '/Dir-Hello/SKILL.md'), 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \is_file(self::TARGET_DIR . '/dir-hello/SKILL.md'),
            'a scoped clean must not delete a different skill that differs only by case',
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function scopedCleanReplacesASkillInstalledUnderAnotherCase(): void
    {
        \mkdir(self::TARGET_DIR . '/Dir-Hello', 0o777, true);
        if (!\is_dir(self::TARGET_DIR . '/dir-hello')) {
            throw new SkipTest('the file system is case-sensitive');
        }
        \file_put_contents(self::TARGET_DIR . '/Dir-Hello/SKILL.md', "---\nname: dir-hello\n---\nold\n");
        \file_put_contents(self::TARGET_DIR . '/Dir-Hello/stale.md', 'left from an earlier install');

        $process = $this->runUpdate('project/local-skills', '--clean');

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::TARGET_DIR . '/dir-hello/SKILL.md'), 'stderr: ' . $process->getErrorOutput());
        Assert::false(
            \is_file(self::TARGET_DIR . '/dir-hello/stale.md'),
            'the same directory under another case must be cleaned, not merged into',
        );

        $show = $this->runShow()->getOutput();
        Assert::true(
            \preg_match('/^\s+OK\s+dir-hello\s/m', $show) === 1,
            'show must pair the skill with its directory. Got: ' . $show,
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills-eszett'],
            ['from' => 'dir', 'path' => './local-skills-eszett-ascii'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function namesFileSystemsKeepApartDoNotConflict(): void
    {
        // Without both extensions `straße` is refused rather than installed.
        if (!SkillConflict::foldsUnicode()) {
            throw new SkipTest('mbstring or intl is not available');
        }

        // Full case folding maps `ß` to `ss`; file systems do not.
        $process = $this->runUpdate();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::TARGET_DIR . '/straße/SKILL.md'), 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::TARGET_DIR . '/strasse/SKILL.md'));
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
            ['from' => 'dir', 'path' => './local-skills-case'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function showFlagsSkillNamesDifferingOnlyByCase(): void
    {
        $out = $this->runShow()->getOutput();

        foreach (['dir-hello' => 'project/local-skills-case', 'Dir-Hello' => 'project/local-skills'] as $name => $partner) {
            Assert::true(
                \preg_match('/!!\s+' . \preg_quote($name, '/') . '\s.*with ' . \preg_quote($partner, '/') . '/', $out) === 1,
                $name . ' must be flagged as conflicting with ' . $partner . '. Got: ' . $out,
            );
        }
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
            ['from' => 'dir', 'path' => './local-skills-case'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function aScopedShowFlagsAConflictWithADonorItLeavesOut(): void
    {
        // `skills:update project/local-skills-case` aborts on this conflict.
        $out = $this->runShow('project/local-skills-case')->getOutput();

        Assert::true(
            \preg_match('/!!\s+Dir-Hello\s.*with project\/local-skills\b/', $out) === 1,
            'the scoped report must agree with the scoped run. Got: ' . $out,
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './no-such-dir'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function missingDirSourceFailsInTheListingAndStillSucceeds(): void
    {
        // No `-v`: a declared donor that produced nothing has to be
        // visible at default verbosity, otherwise the only signal is a
        // silently shorter list of packages.
        $process = $this->runUpdate();
        $out = $process->getOutput();

        // A missing directory degrades gracefully: the run still exits 0.
        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \str_contains($out, 'dir:./no-such-dir')
            && \str_contains($out, '[failed] directory does not exist'),
            'the failure row must name the missing directory. Got: ' . $out,
        );
        // The exact count is the sandbox's business — it ships other
        // broken fixtures too. What this pins is that the tally reaches
        // the summary line at all, so a reader who only sees the last
        // line still knows something was dropped.
        Assert::true(
            \str_contains($out, 'donor(s) failed)'),
            'the summary line must carry the failure tally. Got: ' . $out,
        );
        Assert::false(\is_file(self::TARGET_DIR . '/dir-hello/SKILL.md'));
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './no-such-dir'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function missingDirSourceIsListedAsFailedByShow(): void
    {
        $process = $this->runShow();
        $out = $process->getOutput();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \str_contains($out, 'source-failed')
            && \str_contains($out, 'dir:./no-such-dir'),
            'show must list the unresolvable entry under Skipped:. Got: ' . $out,
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-skills'],
        ],
        'trusted' => ['acme/skills-basic', 'acme/skills-pro'],
    ])]
    public function showListsTheDirDonorAndItsSkills(): void
    {
        $process = $this->runShow();
        $out = $process->getOutput();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        // The derived donor name is `<project-basename>/local-skills`.
        Assert::true(
            \str_contains($out, 'local-skills'),
            'show must list the dir donor. Got: ' . $out,
        );
        Assert::true(
            \str_contains($out, 'dir-hello'),
            'show must list the dir donor skills. Got: ' . $out,
        );
        Assert::true(
            \str_contains($out, '[declared in skills.json]'),
            'a sources[] donor is trusted by its declaration, not by a trust list. Got: ' . $out,
        );
    }

    private function runUpdate(string ...$args): Process
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

    private function runShow(string ...$args): Process
    {
        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            \rtrim('skills:show ' . \implode(' ', $args)),
            timeout: 60,
            mustSucceed: false,
        );
    }
}
