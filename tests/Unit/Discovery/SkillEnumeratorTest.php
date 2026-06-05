<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery;

use Internal\Path;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Discovery\SkillEnumerator;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
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
}
