<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery;

use Internal\Path;
use LLM\Skills\Discovery\DiscoveredSkill;
use LLM\Skills\Discovery\SkillTreeScanner;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
final class SkillTreeScannerTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-scan-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function findsFlatSkillUnderSkillsRoot(): void
    {
        $this->makeSkill('skills/greeting');

        $skills = $this->scan();

        Assert::same($this->names($skills), ['greeting']);
        Assert::same($skills[0]->container, 'skills');
    }

    public function findsSkillUnderDotClaudeRoot(): void
    {
        $this->makeSkill('.claude/skills/review');

        $skills = $this->scan();

        Assert::same($this->names($skills), ['review']);
        Assert::same($skills[0]->container, '.claude/skills');
    }

    public function findsCatalogSkillsTwoLevelsDeep(): void
    {
        // <container>/<category>/<name>/SKILL.md
        $this->makeSkill('skills/php/refactor');
        $this->makeSkill('skills/php/migrate');

        $skills = $this->scan();

        Assert::same($this->names($skills), ['migrate', 'refactor']);
        // Container is the category directory the skill actually lives in.
        foreach ($skills as $skill) {
            Assert::same($skill->container, 'skills/php');
        }
    }

    public function doesNotDescendIntoAFoundSkill(): void
    {
        // Shadowing: a SKILL.md nested below another skill is ignored — a skill
        // cannot contain a nested skill.
        $this->makeSkill('skills/outer');
        $this->makeSkill('skills/outer/inner');

        $skills = $this->scan();

        Assert::same($this->names($skills), ['outer']);
    }

    public function findsSkillsAcrossMultipleContainers(): void
    {
        $this->makeSkill('skills/alpha');
        $this->makeSkill('.cursor/skills/beta');

        $skills = $this->scan();

        Assert::same($this->names($skills), ['alpha', 'beta']);
    }

    public function fallbackFindsSkillsInNonConventionalLocationsWhenNoContainerMatches(): void
    {
        // No conventional container exists, but a SKILL.md sits in a custom
        // path — the bounded recursion must still surface it.
        $this->makeSkill('docs/guides/onboarding');

        $skills = $this->scan();

        Assert::same($this->names($skills), ['onboarding']);
        Assert::same($skills[0]->container, 'docs/guides');
    }

    public function fallbackIsNotUsedWhenAConventionalContainerHasSkills(): void
    {
        // A conventional skill exists, so the fallback recursion never runs —
        // a stray SKILL.md elsewhere must NOT be picked up.
        $this->makeSkill('skills/main');
        $this->makeSkill('examples/sample');

        $skills = $this->scan();

        Assert::same($this->names($skills), ['main']);
    }

    public function fallbackSkipsDependencyAndVcsDirectories(): void
    {
        // SKILL.md files buried in vendor/node_modules/.git are noise, never
        // first-party skills.
        $this->makeSkill('vendor/acme/pkg/skills/nope');
        $this->makeSkill('node_modules/dep/skills/nope2');
        $this->makeSkill('.git/hooks/nope3');

        $skills = $this->scan();

        Assert::same($skills, []);
    }

    public function fallbackDoesNotCrossIntoNestedPackages(): void
    {
        // A directory carrying its own composer.json is a separate package;
        // its skills belong to it, not to the package being scanned.
        $nested = $this->tmp . '/packages/sub';
        \mkdir($nested, 0o777, true);
        \file_put_contents($nested . '/composer.json', '{"name":"acme/sub"}');
        $this->makeSkill('packages/sub/skills/inner');

        Assert::same($this->scan(), []);
    }

    public function fallbackSkipsHiddenDirectories(): void
    {
        // No conventional container exists; a SKILL.md buried in a hidden dir
        // (other than the known .claude/.agents/.cursor roots) is not chased.
        $this->makeSkill('.config/secret');

        Assert::same($this->scan(), []);
    }

    public function returnsEmptyWhenThereAreNoSkillMdFiles(): void
    {
        \mkdir($this->tmp . '/src', 0o777, true);
        \file_put_contents($this->tmp . '/README.md', '# readme');

        Assert::same($this->scan(), []);
    }

    public function returnsEmptyWhenPackageRootDoesNotExist(): void
    {
        $skills = (new SkillTreeScanner())->scan(Path::create($this->tmp . '/missing'));

        Assert::same($skills, []);
    }

    public function rejectsSkillDirectoryThatEscapesPackageRootViaSymlink(): void
    {
        // Junction-safety: a symlinked skills/ pointing OUTSIDE the package
        // must not yield skills. On hosts without symlink privilege the link
        // can't be created; fall back to asserting the missing-root case.
        $outside = $this->tmp . '/outside';
        \mkdir($outside . '/evil', 0o777, true);
        \file_put_contents($outside . '/evil/SKILL.md', '# evil');

        $packageRoot = $this->tmp . '/package';
        \mkdir($packageRoot, 0o777, true);

        $linked = @\symlink($outside, $packageRoot . '/skills');
        if (!$linked) {
            Assert::same(
                (new SkillTreeScanner())->scan(Path::create($packageRoot)),
                [],
                'symlink unavailable on this host — verifying a skill-less root yields []',
            );
            return;
        }

        $skills = (new SkillTreeScanner())->scan(Path::create($packageRoot));

        Assert::same($skills, []);
    }

    public function doesNotDiscoverSkillsInsideALinkedSubdirectory(): void
    {
        // A symlink/junction inside a container is not first-party content and
        // could point anywhere; the scan must not follow it into discovery,
        // even when its target sits within the package root (so the realpath
        // containment check alone would not catch it).
        $this->makeSkill('skills/real');

        $planted = $this->tmp . '/outside/planted';
        \mkdir($planted, 0o777, true);
        \file_put_contents($planted . '/SKILL.md', "---\nname: planted\n---\nbody");

        $made = Filesystem::makeDirLink($this->tmp . '/outside', $this->tmp . '/skills/linked');
        if (!$made) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        // Only the genuine directory is discovered; the linked subtree is not.
        Assert::same($this->names($this->scan()), ['real']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @return list<DiscoveredSkill>
     */
    private function scan(): array
    {
        return (new SkillTreeScanner())->scan(Path::create($this->tmp));
    }

    /**
     * Create a skill directory (with a `SKILL.md`) at `$relDir` under the temp root.
     */
    private function makeSkill(string $relDir): void
    {
        $dir = $this->tmp . '/' . $relDir;
        \mkdir($dir, 0o777, true);
        \file_put_contents($dir . '/SKILL.md', "---\nname: {$relDir}\n---\nbody");
    }

    /**
     * @param list<DiscoveredSkill> $skills
     *
     * @return list<string> the skill directory names, sorted
     */
    private function names(array $skills): array
    {
        $names = \array_map(static fn(DiscoveredSkill $s): string => $s->name, $skills);
        \sort($names);

        return $names;
    }
}
