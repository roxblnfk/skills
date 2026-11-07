<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Composer\WithSkillsJson;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance coverage for a donor whose root directory is the skill itself:
 * `SKILL.md` sits at the donor root instead of under a skills container.
 *
 * A skill found at most two levels below the root turns the root `SKILL.md`
 * into a catalog index; a deeper one is content of the root skill.
 *
 * The fixtures are committed `dir` donors inside the sandbox project:
 * - `local-root-skill/` — frontmatter `name: root-hello`, plus a supporting
 *   `references/guide.md`;
 * - `local-root-skill-unnamed/` — no frontmatter, so the name comes from the
 *   directory-derived package name;
 * - `local-root-skill-nested/` — a root `SKILL.md` next to `examples/rn-demo/SKILL.md`;
 * - `local-root-skill-toplevel/` — a root `SKILL.md` index next to the
 *   top-level skills `pdf-tool/` and `docx-tool/`;
 * - `local-root-skill-agent-copy/` — a root `SKILL.md` index next to the
 *   top-level `pdf-agent/` and an author's `.claude/skills/` holding
 *   `dev-helper/` and an installed copy of `pdf-agent/`;
 * - `local-root-skill-dist-copy/` — a root `SKILL.md` index next to
 *   `skills/copy-pdf/` and a build copy at `dist/copy-pdf/`;
 * - `local-root-skill-container-index/` — a root index and a `skills/SKILL.md`
 *   index above `skills/ci-pdf/`;
 * - `local-root-skill-hidden-copies/` — a root index, `hc-pdf/`, and the same
 *   `hc-helper/` in both `.agents/skills/` and `.claude/skills/`;
 * - `local-root-skill-shallow-resources/` — a root index, `sr-pdf/`, and a
 *   namesake at `resources/skills/sr-pdf/`;
 * - `local-root-skill-catalog/` — a root `SKILL.md` index next to skills in the
 *   conventional `skills/` container and in `.claude/skills/`;
 * - `local-root-skill-resources/` — a root skill shipping
 *   `resources/skills/res-tool/SKILL.md`;
 * - `local-root-skill-deep-catalog/` — a root `SKILL.md` next to a skill four
 *   levels down, in `skills/lang/php/`;
 * - `local-root-skill-hidden-category/` — a root `SKILL.md` next to a skill in
 *   the hidden `skills/.system/` category;
 * - `local-root-skill-template/` — a root skill with a hidden scaffold at
 *   `skills/.template/`;
 * - `local-root-skill-unreachable/` — a root skill whose only nested `SKILL.md`
 *   sits six levels down, past the scan's reach;
 * - `local-root-skill-dotdot/`, `local-root-skill-dot/` — no frontmatter name
 *   and a `composer.json` whose package name ends in `..` / `.`;
 * - `local-root-skill-trailing-dot/` — the same with the package name `evil/foo.`;
 * - `local-root-skill-device-space/` — the same with the package name `evil/nul .md`;
 * - `local-root-skill-dotgit/` — the same with the package name `evil/.git`;
 * - `local-root-skill-long-name/` — a frontmatter name of 65 characters;
 * - `local-root-skill-bad-name/` — the frontmatter name `Bad_Name`;
 * - `local-root-skill-archived/` — a root index, `skills/ar-pdf/`, and its copy
 *   in `skills/.archive/`;
 * - `local-root-skill-twin-namesakes/` — a root index, `skills/tn-x/`, and
 *   `tn-pdf` in both `a/` and `b/`;
 * - `local-root-skill-nested-name/` — the name `nn-tool` with a trailing comment,
 *   and a `metadata` map holding a `name` of its own;
 * - `local-root-host/` — a root skill that also contains the sync target.
 */
#[Test]
final class SkillsRootSkillTest
{
    private const AGENTS_DIR = Info::PROJECT_DIR . '/.agents';
    private const TARGET_DIR = self::AGENTS_DIR . '/skills';
    private const HOST_TARGET_ROOT = Info::PROJECT_DIR . '/local-root-host/.agents';
    private const LINKED_FIXTURE = Info::PROJECT_DIR . '/local-root-skill-linked';

    #[BeforeTest]
    public static function reset(): void
    {
        self::clean();
    }

    #[AfterTest]
    public static function cleanup(): void
    {
        self::clean();
    }

    public function rootSkillIsInstalledUnderItsFrontmatterName(): void
    {
        $process = $this->runAdd('./local-root-skill');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \is_file(self::TARGET_DIR . '/root-hello/SKILL.md'),
            'the root SKILL.md must land under its frontmatter name. ' . $this->diagnostics($process),
        );
        Assert::true(
            \is_file(self::TARGET_DIR . '/root-hello/references/guide.md'),
            'supporting files travel with the root skill',
        );
        Assert::false(\is_dir(self::TARGET_DIR . '/local-root-skill'));
    }

    public function rootSkillWithoutFrontmatterNameUsesTheRepositoryName(): void
    {
        $process = $this->runAdd('./local-root-skill-unnamed');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \is_file(self::TARGET_DIR . '/local-root-skill-unnamed/SKILL.md'),
            'without a frontmatter name the repository name is the target directory. '
                . $this->diagnostics($process),
        );
    }

    public function aNestedNameInTheFrontmatterDoesNotRenameTheRootSkill(): void
    {
        $process = $this->runAdd('./local-root-skill-nested-name');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(\is_file(self::TARGET_DIR . '/nn-tool/SKILL.md'), $this->diagnostics($process));
        Assert::false(\is_dir(self::TARGET_DIR . '/nn-helper'));
        Assert::false(\str_contains($process->getOutput() . $process->getErrorOutput(), '[rename]'));
    }

    public function frontmatterNameOverTheLengthLimitFallsBackToTheRepositoryName(): void
    {
        $process = $this->runAdd('./local-root-skill-long-name');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \is_file(self::TARGET_DIR . '/local-root-skill-long-name/SKILL.md'),
            'a name over 64 characters must not become the directory name. ' . $this->diagnostics($process),
        );
    }

    public function aSkillTwoLevelsDownTurnsTheRootIndexIntoACatalog(): void
    {
        // `examples/rn-demo/SKILL.md` is shallow enough to be a skill of its own,
        // so the archive is a catalog and its root SKILL.md an index.
        $process = $this->runAdd('./local-root-skill-nested');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \is_file(self::TARGET_DIR . '/rn-demo/SKILL.md'),
            'the shallow skill must be installed on its own. ' . $this->diagnostics($process),
        );
        Assert::false(\is_dir(self::TARGET_DIR . '/root-nested'), 'the root index is not a skill then');
    }

    public function catalogSkillsWinOverARootSkillMd(): void
    {
        $process = $this->runAdd('./local-root-skill-catalog');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \is_file(self::TARGET_DIR . '/catalog-a/SKILL.md') && \is_file(self::TARGET_DIR . '/catalog-b/SKILL.md'),
            'skills in a conventional container must be installed one by one. ' . $this->diagnostics($process),
        );
        Assert::true(
            \is_file(self::TARGET_DIR . '/catalog-hidden/SKILL.md'),
            'skills in agent directories are discovered next to `skills/`. ' . $this->diagnostics($process),
        );
        Assert::false(\is_dir(self::TARGET_DIR . '/catalog-index'));
        Assert::false(\is_dir(self::TARGET_DIR . '/local-root-skill-catalog'));
    }

    public function topLevelSkillsTurnTheRootIndexIntoACatalog(): void
    {
        $process = $this->runAdd('./local-root-skill-toplevel');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \is_file(self::TARGET_DIR . '/pdf-tool/SKILL.md') && \is_file(self::TARGET_DIR . '/docx-tool/SKILL.md'),
            'top-level skills are installed one by one. ' . $this->diagnostics($process),
        );
        Assert::false(\is_dir(self::TARGET_DIR . '/toplevel-index'));
    }

    public function anAgentDirectoryDoesNotHideATopLevelCatalogSkill(): void
    {
        $process = $this->runAdd('./local-root-skill-agent-copy');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \is_file(self::TARGET_DIR . '/pdf-agent/SKILL.md'),
            'a `.claude/skills/` copy must not turn the catalog into one root skill. ' . $this->diagnostics($process),
        );
        Assert::false(\is_dir(self::TARGET_DIR . '/agent-copy-index'));
    }

    public function aBuildCopyOfACatalogSkillDoesNotConflictWithIt(): void
    {
        $process = $this->runAdd('./local-root-skill-dist-copy');

        Assert::same($process->getExitCode(), 0, 'one donor must not conflict with itself. ' . $this->diagnostics($process));
        Assert::same(
            (string) @\file_get_contents(self::TARGET_DIR . '/copy-pdf/SKILL.md'),
            (string) \file_get_contents(Info::PROJECT_DIR . '/local-root-skill-dist-copy/skills/copy-pdf/SKILL.md'),
            'the container skill is the one installed',
        );
    }

    public function anIndexAboveContainerSkillsIsNotInstalledAsASkill(): void
    {
        $process = $this->runAdd('./local-root-skill-container-index');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(\is_file(self::TARGET_DIR . '/ci-pdf/SKILL.md'), $this->diagnostics($process));
        Assert::false(
            \is_dir(self::TARGET_DIR . '/skills'),
            'the `skills/` index would copy every catalog skill a second time',
        );
    }

    public function twoHiddenCopiesOfOneSkillDoNotConflict(): void
    {
        $process = $this->runAdd('./local-root-skill-hidden-copies');

        Assert::same($process->getExitCode(), 0, 'one donor must not conflict with itself. ' . $this->diagnostics($process));
        Assert::true(\is_file(self::TARGET_DIR . '/hc-pdf/SKILL.md'), $this->diagnostics($process));
        Assert::true(\is_file(self::TARGET_DIR . '/hc-helper/SKILL.md'), $this->diagnostics($process));
    }

    public function twoShallowNamesakesConflictInsteadOfOneGoingUnseen(): void
    {
        $process = $this->runAdd('./local-root-skill-twin-namesakes');

        Assert::true(
            \str_contains($process->getErrorOutput(), '[conflict] skill "tn-pdf"'),
            $this->diagnostics($process),
        );
        Assert::false(\is_dir(self::TARGET_DIR . '/tn-pdf'), 'nothing is written on a conflict');
    }

    public function anArchivedCopyInANestedHiddenDirectoryGivesWay(): void
    {
        $process = $this->runAdd('./local-root-skill-archived');

        Assert::same($process->getExitCode(), 0, 'one donor must not conflict with itself. ' . $this->diagnostics($process));
        Assert::true(
            \str_contains((string) \file_get_contents(self::TARGET_DIR . '/ar-pdf/SKILL.md'), 'From skills/ar-pdf.'),
            'the visible skill is the one installed. ' . $this->diagnostics($process),
        );
    }

    public function aShallowSkillOutranksTheRootDespiteADeeperNamesake(): void
    {
        $process = $this->runAdd('./local-root-skill-shallow-resources');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(\is_file(self::TARGET_DIR . '/sr-pdf/SKILL.md'), $this->diagnostics($process));
        Assert::false(\is_dir(self::TARGET_DIR . '/shallow-resources-index'), 'the repository is a catalog');
    }

    public function aResourcesSkillIsContentOfTheRootSkill(): void
    {
        $process = $this->runAdd('./local-root-skill-resources');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \is_file(self::TARGET_DIR . '/root-resources/resources/skills/res-tool/SKILL.md'),
            '`resources/skills/<name>/` is too deep to outrank the root. ' . $this->diagnostics($process),
        );
        Assert::false(\is_dir(self::TARGET_DIR . '/res-tool'));
    }

    public function aSkillInAHiddenCategoryIsContentOfTheRootSkill(): void
    {
        // `skills/.system/sys-tool/` sits three levels down: too deep to outrank the root.
        $process = $this->runAdd('./local-root-skill-hidden-category');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \is_file(self::TARGET_DIR . '/hidden-category-index/skills/.system/sys-tool/SKILL.md'),
            'the deeper skill travels inside the root skill. ' . $this->diagnostics($process),
        );
        Assert::false(\is_dir(self::TARGET_DIR . '/sys-tool'));
    }

    public function hiddenScaffoldUnderSkillsDoesNotOutrankTheRootSkill(): void
    {
        $process = $this->runAdd('./local-root-skill-template');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \is_file(self::TARGET_DIR . '/root-template/SKILL.md'),
            'a skill the sync never installs must not displace the root skill. ' . $this->diagnostics($process),
        );
    }

    public function rootSkillStaysWhenNoPublishedSkillIsWithinReach(): void
    {
        $process = $this->runAdd('./local-root-skill-unreachable');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \is_file(self::TARGET_DIR . '/root-unreachable/skills/a/b/c/d/e/SKILL.md'),
            'a SKILL.md the scan cannot reach is content of the root skill. ' . $this->diagnostics($process),
        );
    }

    public function aDeepCatalogSkillIsContentOfTheRootSkill(): void
    {
        $process = $this->runAdd('./local-root-skill-deep-catalog');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \is_file(self::TARGET_DIR . '/deep-catalog-index/skills/lang/php/deep-testing/SKILL.md'),
            'a skill four levels down travels inside the root skill. ' . $this->diagnostics($process),
        );
        Assert::false(\is_dir(self::TARGET_DIR . '/deep-testing'));
    }

    public function rootSkillMdThatIsALinkIsNotADonor(): void
    {
        // Built at run time: a committed symlink does not survive a Windows checkout.
        \mkdir(self::LINKED_FIXTURE, 0o777, true);
        \file_put_contents(self::LINKED_FIXTURE . '.md', "---\nname: root-linked\n---\nbody");
        if (!@\symlink(self::LINKED_FIXTURE . '.md', self::LINKED_FIXTURE . '/SKILL.md')) {
            throw new SkipTest('platform refuses file symlink creation');
        }

        $process = $this->runAdd('./local-root-skill-linked');

        Assert::notSame($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::false(
            \is_dir(self::TARGET_DIR . '/root-linked'),
            'a skill would be installed without its linked SKILL.md. ' . $this->diagnostics($process),
        );
    }

    public function packageNameEndingInDotDotNeverWritesOutsideItsSkillDirectory(): void
    {
        $process = $this->runAdd('./local-root-skill-dotdot');

        Assert::false(
            \is_file(self::AGENTS_DIR . '/SKILL.md'),
            'a `..` package name must not turn the parent of the target into the skill directory. '
                . $this->diagnostics($process),
        );
        Assert::false(\is_file(self::TARGET_DIR . '/SKILL.md'));
    }

    public function packageNameEndingInDotNeverWritesIntoTheTargetRoot(): void
    {
        $process = $this->runAdd('./local-root-skill-dot');

        Assert::false(
            \is_file(self::TARGET_DIR . '/SKILL.md'),
            'a `.` package name must not turn the target itself into the skill directory. '
                . $this->diagnostics($process),
        );
    }

    public function packageNameWithATrailingDotIsSkippedWithAWarning(): void
    {
        // Windows strips the trailing dot, so `foo.` would share the `foo`
        // directory with an unrelated skill of that name.
        $process = $this->runAdd('./local-root-skill-trailing-dot -v');

        Assert::notSame($process->getExitCode(), 0, 'a donor that installs nothing must not be registered');
        Assert::false(\is_file(Info::PROJECT_DIR . '/skills.json'), $this->diagnostics($process));
        Assert::false(
            \is_dir(self::TARGET_DIR . '/foo') || \is_dir(self::TARGET_DIR . '/foo.'),
            'a name Windows rewrites must not be installed. ' . $this->diagnostics($process),
        );
        Assert::true(
            \str_contains($process->getOutput() . $process->getErrorOutput(), 'the root SKILL.md has no usable name'),
            'the skipped root skill must be explained. ' . $this->diagnostics($process),
        );
    }

    public function packageNameThatIsAHiddenDirectoryIsSkippedWithAWarning(): void
    {
        // `.git` in the target would be a repository whose config git obeys.
        $process = $this->runAdd('./local-root-skill-dotgit -v');

        Assert::false(\file_exists(self::TARGET_DIR . '/.git'), $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getOutput() . $process->getErrorOutput(), 'the root SKILL.md has no usable name'),
            'the skipped root skill must be explained. ' . $this->diagnostics($process),
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-root-skill-dotgit'],
        ],
    ])]
    public function aFullCleanKeepsWhatADonorWithNoUsableSkillInstalledBefore(): void
    {
        // The skill was installed under an earlier, valid name; the donor now
        // yields nothing, so wiping the target could not restore it.
        \mkdir(self::TARGET_DIR . '/earlier-name', 0o777, true);
        \file_put_contents(self::TARGET_DIR . '/earlier-name/SKILL.md', "---\nname: earlier-name\n---\nbody\n");

        $process = ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:update --clean',
            timeout: 60,
            mustSucceed: false,
        );

        Assert::notSame($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getErrorOutput(), '--clean refused: evil/.git contributed no skills'),
            $this->diagnostics($process),
        );
        Assert::true(\is_file(self::TARGET_DIR . '/earlier-name/SKILL.md'), 'nothing may be deleted');
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-root-skill-dotgit'],
        ],
    ])]
    public function syncReportsAnUnnamedRootSkillWithoutVerbosity(): void
    {
        // A source registered by hand never went through `skills:add`'s refusal.
        $process = ComposerRunner::run(Path::create(Info::PROJECT_DIR), 'skills:update', timeout: 60, mustSucceed: false);

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getErrorOutput(), '[skip] evil/.git: the root SKILL.md has no usable name'),
            'the skipped root skill must be visible at the default verbosity. ' . $this->diagnostics($process),
        );
    }

    public function packageNameThatIsADeviceNameIsSkippedWithAWarning(): void
    {
        // Windows maps `nul .md` to the NUL device, spaces and extension included.
        $process = $this->runAdd('./local-root-skill-device-space -v');

        Assert::false(\is_dir(self::TARGET_DIR . '/nul .md'), $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getOutput() . $process->getErrorOutput(), 'the root SKILL.md has no usable name'),
            'the skipped root skill must be explained. ' . $this->diagnostics($process),
        );
    }

    #[WithSkillsJson([
        'target' => 'local-root-host/.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-root-host'],
        ],
    ])]
    public function rootSkillContainingTheTargetIsNotCopiedIntoItself(): void
    {
        $process = ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:update',
            timeout: 60,
            mustSucceed: false,
        );

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \is_file(self::HOST_TARGET_ROOT . '/skills/root-host/SKILL.md'),
            'the root skill must be installed. ' . $this->diagnostics($process),
        );
        Assert::false(
            \file_exists(self::HOST_TARGET_ROOT . '/skills/root-host/.agents/skills'),
            'the sync target lives inside the donor and must not be copied into the installed skill',
        );
    }

    #[WithSkillsJson([
        'target' => 'local-root-host/.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-root-host'],
        ],
    ])]
    public function rootSkillContainingTheTargetStaysTheSourceOnTheNextSync(): void
    {
        // The first sync puts a skill into the donor's own `.agents/skills`;
        // the second must still read the root SKILL.md, not that installed copy.
        foreach (['skills:update', 'skills:update --clean'] as $command) {
            $process = ComposerRunner::run(Path::create(Info::PROJECT_DIR), $command, timeout: 60, mustSucceed: false);
            Assert::same($process->getExitCode(), 0, $command . ': ' . $this->diagnostics($process));
        }

        Assert::same(
            (string) @\file_get_contents(self::HOST_TARGET_ROOT . '/skills/root-host/SKILL.md'),
            (string) \file_get_contents(Info::PROJECT_DIR . '/local-root-host/SKILL.md'),
            'the installed root skill must still match its source after a repeated sync. '
                . $this->diagnostics($process),
        );
    }

    #[WithSkillsJson([
        'target' => 'local-root-host/.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-root-host'],
        ],
    ])]
    public function rootSkillContainingTheTargetIsInSyncAfterTheSync(): void
    {
        $update = ComposerRunner::run(Path::create(Info::PROJECT_DIR), 'skills:update', timeout: 60, mustSucceed: false);
        Assert::same($update->getExitCode(), 0, $this->diagnostics($update));

        $show = ComposerRunner::run(Path::create(Info::PROJECT_DIR), 'skills:show', timeout: 60, mustSucceed: false);
        $row = \array_values(\array_filter(
            \preg_split('/\R/', $show->getOutput()) ?: [],
            static fn(string $line): bool => \preg_match('/^\s+\S+\s+root-host\s/', $line) === 1,
        ));

        Assert::true($row !== [], 'the root skill must be listed. ' . $this->diagnostics($show));
        Assert::true(\str_starts_with(\ltrim($row[0]), 'OK '), 'the root skill must be in sync. Got: ' . $row[0]);
    }

    #[WithSkillsJson([
        'target' => 'local-root-host/.AGENTS/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-root-host'],
        ],
    ])]
    public function targetSpelledInADifferentCaseIsStillExcluded(): void
    {
        // The directory on disk is `.agents`; the config spells it `.AGENTS`.
        \mkdir(self::HOST_TARGET_ROOT . '/skills', 0o777, true);
        if (!\is_dir(Info::PROJECT_DIR . '/local-root-host/.AGENTS')) {
            throw new SkipTest('the file system is case-sensitive');
        }

        $process = ComposerRunner::run(Path::create(Info::PROJECT_DIR), 'skills:update', timeout: 60, mustSucceed: false);

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(\is_file(self::HOST_TARGET_ROOT . '/skills/root-host/SKILL.md'), $this->diagnostics($process));
        Assert::false(
            \file_exists(self::HOST_TARGET_ROOT . '/skills/root-host/.agents/skills'),
            'the target must be excluded whatever its spelling',
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './.agents/skills/hand-made'],
        ],
    ])]
    public function rootSkillInsideTheTargetSurvivesACleanSync(): void
    {
        // A skill written by hand straight into the target and registered as a
        // `dir` source: `--clean` must not delete it, and the copy onto itself
        // must not truncate it.
        $skillMd = "---\nname: hand-made\n---\nWritten by hand.\n";
        \mkdir(self::TARGET_DIR . '/hand-made', 0o777, true);
        \file_put_contents(self::TARGET_DIR . '/hand-made/SKILL.md', $skillMd);

        $process = ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:update --clean',
            timeout: 60,
            mustSucceed: false,
        );

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::same(
            (string) @\file_get_contents(self::TARGET_DIR . '/hand-made/SKILL.md'),
            $skillMd,
            'the source inside the target must survive the sync. ' . $this->diagnostics($process),
        );
        Assert::same(
            \substr_count($process->getErrorOutput(), '[kept] hand-made'),
            1,
            'the skipped removal must be reported once, before anything is deleted. ' . $this->diagnostics($process),
        );
        Assert::false(
            \str_contains($process->getOutput(), '[remove] hand-made'),
            'a kept skill must not be listed as removed. ' . $this->diagnostics($process),
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './.agents/skills/hand-made'],
            ['from' => 'dir', 'path' => './local-skills'],
            ['from' => 'dir', 'path' => './local-skills-case'],
        ],
    ])]
    public function aSyncAbortedByAConflictReportsNoKeptSource(): void
    {
        \mkdir(self::TARGET_DIR . '/hand-made', 0o777, true);
        \file_put_contents(self::TARGET_DIR . '/hand-made/SKILL.md', "---\nname: hand-made\n---\nbody\n");

        $process = ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:update --clean',
            timeout: 60,
            mustSucceed: false,
        );

        Assert::notSame($process->getExitCode(), 0, 'the case-only clash must abort. ' . $this->diagnostics($process));
        Assert::false(
            \str_contains($process->getErrorOutput(), '[kept]'),
            'nothing ran, so no purge decision may be reported. ' . $this->diagnostics($process),
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-root-skill-unnamed', 'package' => 'pdf-tool'],
        ],
    ])]
    public function rootSkillTakesAPackageOverrideWithoutAVendorWhole(): void
    {
        $process = ComposerRunner::run(Path::create(Info::PROJECT_DIR), 'skills:update', timeout: 60, mustSucceed: false);

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \is_file(self::TARGET_DIR . '/pdf-tool/SKILL.md'),
            'the whole package override names the root skill. ' . $this->diagnostics($process),
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-root-skill-bad-name', 'skills' => ['local-root-skill-bad-name']],
        ],
    ])]
    public function anAllowlistSelectsARenamedRootSkillByItsInstalledName(): void
    {
        $process = ComposerRunner::run(Path::create(Info::PROJECT_DIR), 'skills:update', timeout: 60, mustSucceed: false);

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \is_file(self::TARGET_DIR . '/local-root-skill-bad-name/SKILL.md'),
            'the name the rename notice reports must select the skill. ' . $this->diagnostics($process),
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-root-skill-unnamed', 'package' => 'acme/my:skills'],
        ],
    ])]
    public function showNamesTheDonorOfARefusedSkillWhoseNameHoldsAColon(): void
    {
        $out = ComposerRunner::run(Path::create(Info::PROJECT_DIR), 'skills:show', timeout: 60, mustSucceed: false)
            ->getOutput();

        Assert::true(
            \preg_match('/skill-refused\s+acme\/my:skills\s+the root SKILL\.md has no usable name/', $out) === 1,
            'the row must carry the whole package name. Got: ' . $out,
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './local-root-skill-bad-name'],
        ],
    ])]
    public function showListsARenamedRootSkillAsInstalledNotAsMissing(): void
    {
        $update = ComposerRunner::run(Path::create(Info::PROJECT_DIR), 'skills:update', timeout: 60, mustSucceed: false);
        Assert::same($update->getExitCode(), 0, $this->diagnostics($update));
        Assert::true(\is_file(self::TARGET_DIR . '/local-root-skill-bad-name/SKILL.md'), $this->diagnostics($update));
        Assert::true(
            \str_contains($update->getErrorOutput(), '[rename] project/local-root-skill-bad-name: the root SKILL.md name "Bad_Name"'),
            'the rename must be visible at the default verbosity. ' . $this->diagnostics($update),
        );

        $out = ComposerRunner::run(Path::create(Info::PROJECT_DIR), 'skills:show', timeout: 60, mustSucceed: false)
            ->getOutput();

        Assert::true(\preg_match('/^\s+OK\s+local-root-skill-bad-name\s/m', $out) === 1, 'Got: ' . $out);
        Assert::false(
            \preg_match('/source-missing.*local-root-skill-bad-name|local-root-skill-bad-name.*source-missing/', $out) === 1,
            'a renamed root skill is installed, not a missing source. Got: ' . $out,
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './.agents/skills'],
        ],
    ])]
    public function rootSkillWhoseSourceIsTheTargetIsSkippedWithAWarning(): void
    {
        \mkdir(self::TARGET_DIR, 0o777, true);
        \file_put_contents(self::TARGET_DIR . '/SKILL.md', "---\nname: self-target\n---\nbody\n");

        $process = ComposerRunner::run(Path::create(Info::PROJECT_DIR), 'skills:update', timeout: 60, mustSucceed: false);

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::false(\is_dir(self::TARGET_DIR . '/self-target'), $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getErrorOutput(), '[skip] self-target: its source is the target directory itself'),
            'the skipped skill must be explained. ' . $this->diagnostics($process),
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './.agents/skills'],
        ],
    ])]
    public function showListsARootSkillWhoseSourceIsTheTargetAsSkipped(): void
    {
        \mkdir(self::TARGET_DIR, 0o777, true);
        \file_put_contents(self::TARGET_DIR . '/SKILL.md', "---\nname: self-target\n---\nbody\n");

        $process = ComposerRunner::run(Path::create(Info::PROJECT_DIR), 'skills:show', timeout: 60, mustSucceed: false);
        $out = $process->getOutput();

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \preg_match('/target-is-source\s+\S+\s+skill "self-target": its source is the target directory itself/', $out) === 1,
            'show must list the skill as skipped, as the sync does. ' . $this->diagnostics($process),
        );
        Assert::true(
            \preg_match('/^\s+\S+\s+self-target\s/m', $out) === 0,
            'the skill is not listed as one waiting to be written. ' . $this->diagnostics($process),
        );
    }

    #[WithSkillsJson([
        'target' => '.agents/skills',
        'sources' => [
            ['from' => 'dir', 'path' => './.agents/skills'],
        ],
        'trusted' => ['acme/skills-basic'],
    ])]
    public function showFlagsAConflictOnARootSkillWhoseSourceIsTheTarget(): void
    {
        \mkdir(self::TARGET_DIR, 0o777, true);
        \file_put_contents(self::TARGET_DIR . '/SKILL.md', "---\nname: greeting\n---\nbody\n");

        $update = ComposerRunner::run(Path::create(Info::PROJECT_DIR), 'skills:update', timeout: 60, mustSucceed: false);
        $show = ComposerRunner::run(Path::create(Info::PROJECT_DIR), 'skills:show', timeout: 60, mustSucceed: false);
        $out = $show->getOutput();

        Assert::notSame($update->getExitCode(), 0, 'the conflict aborts the sync. ' . $this->diagnostics($update));
        Assert::same($show->getExitCode(), 0, $this->diagnostics($show));
        Assert::true(
            \preg_match('/ !! +greeting +with acme\/skills-basic/', $out) === 1,
            'show must flag the root skill whose conflict aborts the sync. ' . $this->diagnostics($show),
        );
        Assert::false(\str_contains($out, 'target-is-source'), $this->diagnostics($show));
    }

    private static function clean(): void
    {
        Filesystem::removeRecursive(self::AGENTS_DIR);
        Filesystem::removeRecursive(self::HOST_TARGET_ROOT);
        Filesystem::removeRecursive(self::LINKED_FIXTURE);
        Filesystem::removeRecursive(self::LINKED_FIXTURE . '.md');
    }

    /**
     * @param non-empty-string $args
     */
    private function runAdd(string $args): Process
    {
        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:add ' . $args,
            timeout: 60,
            mustSucceed: false,
        );
    }

    private function diagnostics(Process $process): string
    {
        return 'stdout: ' . $process->getOutput() . "\nstderr: " . $process->getErrorOutput();
    }
}
