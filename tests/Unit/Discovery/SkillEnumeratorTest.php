<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery;

use Internal\Path;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Discovery\SkillEnumerator;
use LLM\Skills\Discovery\SkillNotice;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataSet;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
final class SkillEnumeratorTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-enum-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function enumeratesEachSubdirectoryOfSourceAsASkill(): void
    {
        $donor = $this->makeDonor('acme/basic', 'src', [
            'greeting/SKILL.md' => '# Greeting',
            'code-review/SKILL.md' => '# Review',
        ]);

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same(\count($result->skills), 2);
        Assert::same($result->warnings, []);
        $names = \array_map(static fn($s) => $s->name, $result->skills);
        \sort($names);
        Assert::same($names, ['code-review', 'greeting']);
    }

    public function attachesPackageNameToEachEnumeratedSkill(): void
    {
        $donor = $this->makeDonor('acme/basic', 'src', ['greeting/SKILL.md' => '# Greeting']);

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same($result->skills[0]->packageName, 'acme/basic');
    }

    public function ignoresLooseFilesAtSourceRoot(): void
    {
        // A README sitting next to skill directories is not a skill.
        $donor = $this->makeDonor('acme/basic', 'src', [
            'README.md' => 'top-level readme',
            'greeting/SKILL.md' => '# Greeting',
        ]);

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same(\count($result->skills), 1);
        Assert::same($result->skills[0]->name, 'greeting');
    }

    public function emitsWarningWhenSourceDirectoryDoesNotExist(): void
    {
        $packageRoot = $this->tmp . '/vendor/acme/empty';
        \mkdir($packageRoot, 0o777, true);
        $donor = new VendorConfig('acme/empty', Path::create($packageRoot), 'src');

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same($result->skills, []);
        Assert::same(\count($result->warnings), 1);
        Assert::true(\str_contains($result->warnings[0], 'acme/empty'));
        Assert::true(\str_contains($result->warnings[0], 'does not exist'));
    }

    public function continuesPastABrokenDonorAndProducesSkillsFromTheRest(): void
    {
        // First donor's source is missing — must not block the second one.
        $packageRoot = $this->tmp . '/vendor/acme/broken';
        \mkdir($packageRoot, 0o777, true);
        $broken = new VendorConfig('acme/broken', Path::create($packageRoot), 'src');

        $good = $this->makeDonor('acme/good', 'src', ['refactor/SKILL.md' => '# OK']);

        $result = (new SkillEnumerator())->enumerate([$broken, $good]);

        Assert::same(\count($result->warnings), 1);
        Assert::same(\count($result->skills), 1);
        Assert::same($result->skills[0]->packageName, 'acme/good');
    }

    public function aDonorWithNoSourceDirectoryIsNamedInDroppedDonors(): void
    {
        // The typed half of the warning: a caller that deletes before writing
        // has to know a donor's skills are missing from the result, not merely
        // print that they are.
        $packageRoot = $this->tmp . '/vendor/acme/broken';
        \mkdir($packageRoot, 0o777, true);
        $broken = new VendorConfig('acme/broken', Path::create($packageRoot), 'src');

        $good = $this->makeDonor('acme/good', 'src', ['refactor/SKILL.md' => '# OK']);

        $result = (new SkillEnumerator())->enumerate([$broken, $good]);

        Assert::same($result->droppedDonors, ['acme/broken']);
    }

    public function aFullyEnumeratedRunDropsNoDonors(): void
    {
        $donor = $this->makeDonor('acme/good', 'src', ['refactor/SKILL.md' => '# OK']);

        Assert::same((new SkillEnumerator())->enumerate([$donor])->droppedDonors, []);
    }

    public function skillFilterKeepsOnlyTheAllowlistedSkills(): void
    {
        $donor = $this->makeDonor('acme/multi', 'src', [
            'alpha/SKILL.md' => 'A',
            'beta/SKILL.md' => 'B',
            'gamma/SKILL.md' => 'C',
        ])->withSkillFilter(['alpha', 'gamma']);

        $result = (new SkillEnumerator())->enumerate([$donor]);

        $names = \array_map(static fn($s) => $s->name, $result->skills);
        \sort($names);
        Assert::same($names, ['alpha', 'gamma']);
        Assert::same($result->warnings, []);
    }

    public function skillFilterEmitsWarningForDeclaredButMissingNames(): void
    {
        // `oops` was requested but doesn't exist in the donor — surface
        // as a `-v` warning so the user can spot the typo. The rest of
        // the allowlist still syncs.
        $donor = $this->makeDonor('acme/multi', 'src', [
            'alpha/SKILL.md' => 'A',
        ])->withSkillFilter(['alpha', 'oops']);

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same(\count($result->skills), 1);
        Assert::same($result->skills[0]->name, 'alpha');
        Assert::same(\count($result->warnings), 1);
        Assert::true(\str_contains($result->warnings[0], 'acme/multi'));
        Assert::true(\str_contains($result->warnings[0], '"oops"'));
        Assert::true(\str_contains($result->warnings[0], 'not found'));
    }

    public function aDonorWhoseAllowlistSelectsNothingIsDropped(): void
    {
        $stale = $this->makeDonor('acme/stale', 'src', ['alpha/SKILL.md' => 'A'])
            ->withSkillFilter(['gone']);
        $partial = $this->makeDonor('acme/partial', 'src', ['alpha/SKILL.md' => 'A'])
            ->withSkillFilter(['alpha', 'gone']);
        $rowA = $this->makeDonor('acme/mono', 'src-a', ['alpha/SKILL.md' => 'A'])
            ->withSkillFilter(['beta']);
        $rowB = $this->makeDonor('acme/mono', 'src-b', ['beta/SKILL.md' => 'B'])
            ->withSkillFilter(['beta']);

        $result = (new SkillEnumerator())->enumerate([$stale, $partial, $rowA, $rowB]);

        Assert::same($result->droppedDonors, ['acme/stale']);
    }

    public function eachDroppedDonorCarriesWhyItContributedNothing(): void
    {
        $packageRoot = $this->tmp . '/vendor/acme/broken';
        \mkdir($packageRoot, 0o777, true);
        $broken = new VendorConfig('acme/broken', Path::create($packageRoot), 'src');
        $stale = $this->makeDonor('acme/stale', 'src', ['alpha/SKILL.md' => 'A'])
            ->withSkillFilter(['gone', 'lost']);
        $partial = $this->makeDonor('acme/partial', 'src', ['alpha/SKILL.md' => 'A'])
            ->withSkillFilter(['alpha', 'gone']);

        $result = (new SkillEnumerator())->enumerate([$broken, $stale, $partial]);

        Assert::same($result->dropReasons, [
            'acme/broken' => ['source directory "src" does not exist'],
            'acme/stale' => [
                'skill "gone" declared in the skill allowlist but not found in the donor',
                'skill "lost" declared in the skill allowlist but not found in the donor',
            ],
        ]);
    }

    public function skillFilterDoesNotWarnWhenSkillLivesInASiblingSourceRow(): void
    {
        // A multi-directory `source` declaration arrives as several donor
        // rows sharing one package name. A declared skill that lives in one
        // of the directories must not be reported missing by the rows that
        // don't contain it.
        $rowA = $this->makeDonor('acme/mono', 'src-a', ['alpha/SKILL.md' => 'A'])
            ->withSkillFilter(['alpha', 'beta']);
        $rowB = $this->makeDonor('acme/mono', 'src-b', ['beta/SKILL.md' => 'B'])
            ->withSkillFilter(['alpha', 'beta']);

        $result = (new SkillEnumerator())->enumerate([$rowA, $rowB]);

        $names = \array_map(static fn($s) => $s->name, $result->skills);
        \sort($names);
        Assert::same($names, ['alpha', 'beta']);
        Assert::same($result->warnings, []);
    }

    public function skillFilterWarnsOnceForANameMissingFromEverySiblingRow(): void
    {
        // A name absent from ALL of the package's source directories is a
        // genuine typo/stale entry — one warning for the package, not one
        // per row.
        $rowA = $this->makeDonor('acme/mono', 'src-a', ['alpha/SKILL.md' => 'A'])
            ->withSkillFilter(['alpha', 'oops']);
        $rowB = $this->makeDonor('acme/mono', 'src-b', ['beta/SKILL.md' => 'B'])
            ->withSkillFilter(['alpha', 'oops']);

        $result = (new SkillEnumerator())->enumerate([$rowA, $rowB]);

        Assert::same(\count($result->warnings), 1);
        Assert::true(\str_contains($result->warnings[0], 'acme/mono'));
        Assert::true(\str_contains($result->warnings[0], '"oops"'));
    }

    public function emptySkillFilterDropsAllSkillsAndEmitsNoWarnings(): void
    {
        // An explicitly empty allowlist means "the donor is on file but
        // we don't want any of its skills right now" — distinct from
        // `null` ("sync every skill"). Nothing lands and there is
        // nothing to warn about (no missing names to call out).
        $donor = $this->makeDonor('acme/multi', 'src', [
            'alpha/SKILL.md' => 'A',
            'beta/SKILL.md' => 'B',
        ])->withSkillFilter([]);

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same($result->skills, []);
        Assert::same($result->warnings, []);
    }

    public function nullSkillFilterSyncsEverything(): void
    {
        // Sanity check that the filter path is purely additive — a null
        // filter (the default) leaves behaviour identical to before the
        // field existed.
        $donor = $this->makeDonor('acme/two', 'src', [
            'one/SKILL.md' => '1',
            'two/SKILL.md' => '2',
        ]);

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same(\count($result->skills), 2);
    }

    public function filterMatchesAgainstFrontmatterCanonicalNameNotDirectory(): void
    {
        // The user's allowlist uses the canonical name from the
        // SKILL.md `name:` field — `symfony:quality-checks` — even
        // though the directory on disk is just `quality-checks`.
        // The enumerator reads frontmatter, picks the canonical name,
        // and matches the filter against THAT. The dir name still
        // drives the destination directory (Skill::$name).
        $donor = $this->makeDonor('acme/scoped', 'src', [
            'quality-checks/SKILL.md' => "---\nname: symfony:quality-checks\n---\nbody",
            'other/SKILL.md' => "---\nname: other\n---\nbody",
        ])->withSkillFilter(['symfony:quality-checks']);

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::count($result->skills, 1);
        Assert::same($result->skills[0]->name, 'quality-checks');
        Assert::same($result->skills[0]->canonicalName, 'symfony:quality-checks');
        Assert::same($result->warnings, []);
    }

    public function canonicalNameFallsBackToDirectoryWhenFrontmatterIsMissing(): void
    {
        // Pre-existing skills that never bothered with a SKILL.md
        // (or skills whose SKILL.md has no `name:` line) keep working:
        // the canonical name falls back to the directory, so the
        // existing `--skill <dir>` muscle memory still hits them.
        $donor = $this->makeDonor('acme/bare', 'src', [
            'plain/README.md' => 'no frontmatter at all',
            'with-empty-fm/SKILL.md' => "---\n---\nbody",
        ])->withSkillFilter(['plain', 'with-empty-fm']);

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::count($result->skills, 2);
        $names = \array_map(static fn($s) => $s->canonicalName, $result->skills);
        \sort($names);
        Assert::same($names, ['plain', 'with-empty-fm']);
    }

    public function returnsEmptyResultForEmptyDonorList(): void
    {
        $result = (new SkillEnumerator())->enumerate([]);

        Assert::same($result->skills, []);
        Assert::same($result->warnings, []);
    }

    // ── auto-discovered donors (explicit skill directories) ───────────────────

    public function hiddenSkillDirectoriesAreNeverInstalled(): void
    {
        $declared = $this->makeDonor('acme/declared', 'skills', [
            '.git/SKILL.md' => '# planted',
            'ZZZZZZ~9/SKILL.md' => '# planted',
            'greeting/SKILL.md' => '# Greeting',
        ]);
        $discovered = $this->makeDiscoveredDonor('acme/found', 'skills', [
            'skills/.github' => '# planted',
            'skills/review' => '# Review',
        ]);

        $result = (new SkillEnumerator())->enumerate([$declared, $discovered]);

        Assert::same(\array_map(static fn($s) => $s->name, $result->skills), ['greeting', 'review']);
        Assert::same($result->warnings, []);
        Assert::same(self::describe($result->skipped), [
            'acme/declared: skill directory ".git" is hidden, and is not installed',
            'acme/declared: skill directory "ZZZZZZ~9" looks like a Windows short name, which can resolve to '
                . 'another skill\'s directory, and is not installed',
            'acme/found: skill directory ".github" is hidden, and is not installed',
        ]);
    }

    #[DataSet(['.github', 'is hidden'])]
    #[DataSet(['SKILLS~1', 'looks like a Windows short name'])]
    #[DataSet(["caf\xe9", 'is not valid UTF-8'])]
    #[DataSet(['a:b', 'holds a character Windows does not allow'])]
    #[DataSet(["a\tb", 'holds a character Windows does not allow'])]
    #[DataSet(['foo.', 'ends in a dot or space'])]
    #[DataSet(['foo ', 'ends in a dot or space'])]
    #[DataSet(['con', 'is a Windows device name'])]
    #[DataSet(['LPT1.txt', 'is a Windows device name'])]
    public function anyDonorDirectoryNameIsRefusedWhenItIsNotPortable(string $name, string $reason): void
    {
        Assert::true(\str_contains((string) SkillEnumerator::refusedDirectoryName($name), $reason));
    }

    #[DataSet(['greeting'])]
    #[DataSet(['refactor_legacy'])]
    #[DataSet(['Über'])]
    #[DataSet(['a.b'])]
    public function portableDirectoryNamesAreAccepted(string $name): void
    {
        Assert::null(SkillEnumerator::refusedDirectoryName($name));
    }

    public function aDonorWhoseSkillsAreAllRefusedIsDropped(): void
    {
        $unnamed = $this->makeRootSkillDonor('evil/..', '# No frontmatter');
        $declared = $this->makeDonor('acme/hidden-only', 'skills', ['.planted/SKILL.md' => '# planted']);
        $healthy = $this->makeDonor('acme/mixed', 'skills', [
            '.planted/SKILL.md' => '# planted',
            'greeting/SKILL.md' => '# Greeting',
        ]);
        $legacyRow = $this->makeDonor('acme/mono', 'legacy', ['.old/SKILL.md' => '# old']);
        $currentRow = $this->makeDonor('acme/mono', 'skills', ['pdf/SKILL.md' => '# PDF']);

        $result = (new SkillEnumerator())->enumerate([$unnamed, $declared, $healthy, $legacyRow, $currentRow]);

        Assert::same($result->droppedDonors, ['evil/..', 'acme/hidden-only']);
    }

    public function rootSkillFallsBackToAPackageNameWithoutAVendor(): void
    {
        $donor = $this->makeRootSkillDonor('pdf-tool', '# No frontmatter');

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same($result->skills[0]->name, 'pdf-tool');
    }

    public function rootSkillLengthLimitCountsCharactersNotBytes(): void
    {
        $name = \str_repeat('ü', 40);
        $donor = $this->makeRootSkillDonor('acme/' . $name, '# No frontmatter');

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same($result->skills[0]->name, $name);
    }

    public function refusedDirectoriesThatAreNotSelectedSkillsStayQuiet(): void
    {
        $donor = $this->makeDonor('acme/declared', 'skills', [
            '.curated/README.md' => 'a category, not a skill',
            '.planted/SKILL.md' => '# planted',
            'greeting/SKILL.md' => '# Greeting',
        ]);

        $result = (new SkillEnumerator())->enumerate([$donor, $donor->withSkillFilter(['greeting'])]);

        Assert::same(\array_map(static fn($s) => $s->name, $result->skills), ['greeting', 'greeting']);
        Assert::same(
            self::describe($result->skipped),
            ['acme/declared: skill directory ".planted" is hidden, and is not installed'],
        );
    }

    public function discoveredDonorEnumeratesItsExplicitSkillDirectories(): void
    {
        // Auto-discovery can surface skills at a catalog depth that is NOT an
        // immediate subdirectory of `source` (`skills/php/<name>/`). The
        // enumerator must use the explicit directory list, not scan `source`.
        $donor = $this->makeDiscoveredDonor('acme/found', 'skills', [
            'skills/php/refactor' => "---\nname: php:refactor\n---\nbody",
            'skills/php/migrate' => '# Migrate',
        ]);

        $result = (new SkillEnumerator())->enumerate([$donor]);

        $names = \array_map(static fn($s) => $s->name, $result->skills);
        \sort($names);
        Assert::same($names, ['migrate', 'refactor']);
        Assert::same($result->warnings, []);
    }

    public function rootSkillTakesItsDirectoryNameFromFrontmatter(): void
    {
        $donor = $this->makeRootSkillDonor('acme/skill-repo', "---\nname: temporal-developer\n---\nbody");

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same($result->skills[0]->name, 'temporal-developer');
    }

    public function rootSkillWithoutFrontmatterNameUsesTheRepositoryName(): void
    {
        $donor = $this->makeRootSkillDonor('acme/skill-repo', '# No frontmatter');

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same($result->skills[0]->name, 'skill-repo');
    }

    public function rootSkillRejectsAFrontmatterNameThatEscapesTheTarget(): void
    {
        $donor = $this->makeRootSkillDonor('acme/skill-repo', "---\nname: ../../evil\n---\nbody");

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same($result->skills[0]->name, 'skill-repo');
    }

    public function rootSkillWarnsWhenItsFrontmatterNameIsReplaced(): void
    {
        $donor = $this->makeRootSkillDonor('acme/skill-repo', "---\nname: Code_Review\n---\nbody");

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same($result->skills[0]->name, 'skill-repo');
        Assert::same($result->warnings, []);
        Assert::same(self::describe($result->renamed), [
            'acme/skill-repo: the root SKILL.md name "Code_Review" cannot be a directory name, which is lowercase '
                . 'and hyphenated, at most 64 characters, and not a Windows device name such as `con`; '
                . 'installing it as "skill-repo"',
        ]);
    }

    public function rootSkillWarningForADeviceNameDoesNotBlameTheSpelling(): void
    {
        $donor = $this->makeRootSkillDonor('acme/skill-repo', "---\nname: con\n---\nbody");

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::count($result->renamed, 1);
        Assert::true(\str_contains($result->renamed[0]->message, 'not a Windows device name'));
    }

    public function rootSkillRejectsAFrontmatterNameOverTheLengthLimit(): void
    {
        $donor = $this->makeRootSkillDonor('acme/skill-repo', "---\nname: " . \str_repeat('a', 65) . "\n---\nbody");

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same($result->skills[0]->name, 'skill-repo');
    }

    public function rootSkillAcceptsAFrontmatterNameAtTheLengthLimit(): void
    {
        $name = \str_repeat('a', 64);
        $donor = $this->makeRootSkillDonor('acme/skill-repo', "---\nname: {$name}\n---\nbody");

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same($result->skills[0]->name, $name);
    }

    public function aRenamedRootSkillIsSelectedByTheNameItIsInstalledUnder(): void
    {
        $donor = $this->makeRootSkillDonor('acme/skill-repo', "---\nname: Code_Review\n---\nbody");

        $kept = (new SkillEnumerator())->enumerate([$donor->withSkillFilter(['skill-repo'])]);
        $dropped = (new SkillEnumerator())->enumerate([$donor->withSkillFilter(['Code_Review'])]);

        Assert::same($kept->skills[0]->canonicalName, 'skill-repo');
        Assert::count($kept->renamed, 1);
        Assert::same($dropped->skills, []);
        Assert::same($dropped->renamed, [], 'a skill left out by the allowlist is not reported as renamed');
    }

    public function rootSkillWithoutFrontmatterNameDoesNotWarn(): void
    {
        $donor = $this->makeRootSkillDonor('acme/skill-repo', '# No frontmatter');

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same($result->warnings, []);
    }

    #[DataSet(['evil/..'], name: 'parent directory')]
    #[DataSet(['evil/.'], name: 'current directory')]
    #[DataSet(['evil/...'], name: 'all dots')]
    #[DataSet(['evil/foo.'], name: 'trailing dot, stripped by Windows')]
    #[DataSet(['evil/foo '], name: 'trailing space, stripped by Windows')]
    #[DataSet(['evil/a*b'], name: 'character Windows forbids')]
    #[DataSet(["evil/a\tb"], name: 'control character')]
    #[DataSet(['evil/con.txt'], name: 'device name with an extension')]
    #[DataSet(['evil/SKILLS~1'], name: 'Windows short name')]
    #[DataSet(['evil/a~12.md'], name: 'Windows short name with an extension')]
    #[DataSet(['evil/.git'], name: 'hidden repository directory')]
    #[DataSet(['acme/.github'], name: 'hidden directory')]
    #[DataSet(['evil/nul .md'], name: 'device name with spaces before the extension')]
    #[DataSet(['evil/con  .txt'], name: 'device name with several spaces before the extension')]
    #[DataSet(['evil/conin$'], name: 'console input device')]
    #[DataSet(['evil/CONOUT$'], name: 'console output device')]
    #[DataSet(['evil/com¹'], name: 'superscript serial port')]
    #[DataSet(['evil/lpt³'], name: 'superscript parallel port')]
    #[DataSet(["evil/a\xffb"], name: 'invalid UTF-8')]
    #[DataSet(['evil/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'], name: 'over the length limit')]
    public function rootSkillNeverTakesAnUnportableNameFromThePackageName(string $packageName): void
    {
        $donor = $this->makeRootSkillDonor($packageName, '# No frontmatter');

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same($result->skills, []);
        Assert::same($result->warnings, []);
        Assert::count($result->skipped, 1);
        Assert::same($result->skipped[0]->packageName, $packageName);
        Assert::true(\str_starts_with($result->skipped[0]->message, 'the root SKILL.md has no usable name'));
    }

    #[DataSet(['con'])]
    #[DataSet(['nul'])]
    #[DataSet(['aux'])]
    #[DataSet(['prn'])]
    #[DataSet(['com1'])]
    #[DataSet(['lpt9'])]
    public function rootSkillRejectsAWindowsReservedFrontmatterName(string $reserved): void
    {
        $donor = $this->makeRootSkillDonor('acme/skill-repo', "---\nname: {$reserved}\n---\nbody");

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::same($result->skills[0]->name, 'skill-repo');
    }

    public function discoveredDonorReadsCanonicalNameFromFrontmatter(): void
    {
        $donor = $this->makeDiscoveredDonor('acme/found', 'skills', [
            'skills/refactor' => "---\nname: php:refactor\n---\nbody",
        ]);

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::count($result->skills, 1);
        Assert::same($result->skills[0]->name, 'refactor');
        Assert::same($result->skills[0]->canonicalName, 'php:refactor');
    }

    public function discoveredDonorSkipsDirectoriesThatNoLongerHaveSkillMd(): void
    {
        // A directory that vanished (or lost its SKILL.md) between discovery
        // and enumeration must degrade quietly to no phantom skill.
        $donor = $this->makeDiscoveredDonor('acme/found', 'skills', [
            'skills/real' => '# Real',
        ]);
        // Append a path that does not exist on disk.
        $donor = new VendorConfig(
            packageName: $donor->packageName,
            packageRoot: $donor->packageRoot,
            source: $donor->source,
            discovered: true,
            discoveredSkillDirs: [
                ...$donor->discoveredSkillDirs ?? [],
                Path::create($this->tmp . '/vendor/acme/found/skills/ghost'),
            ],
        );

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::count($result->skills, 1);
        Assert::same($result->skills[0]->name, 'real');
        Assert::same($result->warnings, []);
    }

    public function discoveredDonorStillHonoursSkillFilter(): void
    {
        $donor = $this->makeDiscoveredDonor('acme/found', 'skills', [
            'skills/alpha' => '# A',
            'skills/beta' => '# B',
        ])->withSkillFilter(['alpha']);

        $result = (new SkillEnumerator())->enumerate([$donor]);

        Assert::count($result->skills, 1);
        Assert::same($result->skills[0]->name, 'alpha');
    }

    public function discoveredDonorHoldingTheTargetLeavesItsInstalledCopiesOut(): void
    {
        $donor = $this->makeDiscoveredDonor('acme/host', 'skills', [
            'skills/own' => '# Own',
            '.agents/skills/copied' => '# Copied',
        ]);
        $target = $donor->packageRoot->join('.agents/skills');

        $result = (new SkillEnumerator())->enumerate([$donor], $target);

        Assert::same(\array_map(static fn($s) => $s->name, $result->skills), ['own']);
        Assert::same($result->warnings, []);
        Assert::same($result->droppedDonors, []);
    }

    public function declaredSourceHoldingTheTargetDoesNotListItAsASkill(): void
    {
        $donor = $this->makeDonor('acme/host', 'src', [
            'greeting/SKILL.md' => '# Greeting',
            'installed/copied/SKILL.md' => '# Copied',
        ]);

        $result = (new SkillEnumerator())->enumerate([$donor], $donor->sourcePath()->join('installed'));

        Assert::same(\array_map(static fn($s) => $s->name, $result->skills), ['greeting']);
    }

    public function targetSpelledInADifferentCaseIsStillLeftOut(): void
    {
        $donor = $this->makeDiscoveredDonor('acme/host', '.agents/skills', [
            '.agents/skills/copied' => '# Copied',
        ]);
        $target = $donor->packageRoot->join('.AGENTS/skills');
        if (!\is_dir((string) $target)) {
            throw new SkipTest('the file system is case-sensitive');
        }

        $result = (new SkillEnumerator())->enumerate([$donor], $target);

        Assert::same($result->skills, []);
    }

    public function copyReachedThroughALinkIntoTheTargetIsLeftOut(): void
    {
        $donor = $this->makeDiscoveredDonor('acme/host', 'skills', ['.agents/skills/copied' => '# Copied']);
        $root = (string) $donor->packageRoot;
        \mkdir($root . '/.claude', 0o777, true);
        if (!Filesystem::makeDirLink($root . '/.agents/skills', $root . '/.claude/skills')) {
            throw new SkipTest('directory links are unavailable');
        }
        $aliased = new VendorConfig(
            packageName: 'acme/host',
            packageRoot: $donor->packageRoot,
            source: '.claude/skills',
            discovered: true,
            discoveredSkillDirs: [Path::create($root . '/.claude/skills/copied')],
        );

        $result = (new SkillEnumerator())->enumerate([$aliased], $donor->packageRoot->join('.agents/skills'));

        Assert::same($result->skills, []);
    }

    public function allowlistNamingOnlyAnInstalledCopyDropsTheDonor(): void
    {
        $donor = $this->makeDiscoveredDonor('acme/host', '.agents/skills', [
            '.agents/skills/copied' => '# Copied',
        ])->withSkillFilter(['copied']);

        $result = (new SkillEnumerator())->enumerate([$donor], $donor->packageRoot->join('.agents/skills'));

        Assert::same($result->skills, []);
        Assert::same($result->droppedDonors, ['acme/host']);
    }

    public function allowlistWarningSaysAnInstalledCopyDoesNotCount(): void
    {
        $donor = $this->makeDiscoveredDonor('acme/host', 'skills', [
            'skills/own' => '# Own',
            '.agents/skills/copied' => '# Copied',
        ])->withSkillFilter(['copied']);
        $target = $donor->packageRoot->join('.agents/skills');

        $result = (new SkillEnumerator())->enumerate([$donor], $target);

        Assert::count($result->warnings, 1);
        Assert::true(
            \str_contains($result->warnings[0], 'its copy installed in the target ' . $target . ' does not count'),
            $result->warnings[0],
        );
    }

    /**
     * @param non-empty-string $packageName
     * @param non-empty-string $sourceDir
     * @param array<non-empty-string, string> $files "<skill>/<rel-path>" → contents
     */
    private function makeDonor(string $packageName, string $sourceDir, array $files): VendorConfig
    {
        $packageRoot = $this->tmp . '/vendor/' . $packageName;
        $source = $packageRoot . '/' . $sourceDir;
        \mkdir($source, 0o777, true);

        foreach ($files as $rel => $contents) {
            $full = $source . '/' . $rel;
            $dir = \dirname($full);
            if (!\is_dir($dir)) {
                \mkdir($dir, 0o777, true);
            }
            \file_put_contents($full, $contents);
        }

        return new VendorConfig($packageName, Path::create($packageRoot), $sourceDir);
    }

    /**
     * Build an auto-discovered donor: writes each skill's files and points the
     * donor's `discoveredSkillDirs` at the (absolute) skill directories — the
     * way {@see \LLM\Skills\Discovery\DonorDiscovery} populates them from
     * {@see \LLM\Skills\Discovery\SkillTreeScanner}.
     *
     * @param non-empty-string $packageName
     * @param non-empty-string $source container, for display only
     * @param array<non-empty-string, string> $skills "<rel-skill-dir>" → SKILL.md contents
     */
    private function makeDiscoveredDonor(string $packageName, string $source, array $skills): VendorConfig
    {
        $packageRoot = $this->tmp . '/vendor/' . $packageName;
        \mkdir($packageRoot, 0o777, true);

        $dirs = [];
        foreach ($skills as $relDir => $contents) {
            $full = $packageRoot . '/' . $relDir;
            \mkdir($full, 0o777, true);
            \file_put_contents($full . '/SKILL.md', $contents);
            $dirs[] = Path::create($full);
        }

        return new VendorConfig(
            packageName: $packageName,
            packageRoot: Path::create($packageRoot),
            source: $source,
            discovered: true,
            discoveredSkillDirs: $dirs,
        );
    }

    /**
     * @param non-empty-string $packageName
     */
    private function makeRootSkillDonor(string $packageName, string $skillMd): VendorConfig
    {
        $packageRoot = $this->tmp . '/archive-abc123';
        \mkdir($packageRoot, 0o777, true);
        \file_put_contents($packageRoot . '/SKILL.md', $skillMd);

        return new VendorConfig(
            packageName: $packageName,
            packageRoot: Path::create($packageRoot),
            source: '.',
            discoveredSkillDirs: [Path::create($packageRoot)],
            rootSkill: true,
        );
    }

    /**
     * @param list<SkillNotice> $notices
     *
     * @return list<string>
     */
    private static function describe(array $notices): array
    {
        return \array_map(static fn(SkillNotice $notice): string => $notice->describe(), $notices);
    }
}
