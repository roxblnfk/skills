<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Init;

use Internal\Path;
use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Init\AgentWorkspaceProbe;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Unit coverage for the layout `skills:init` proposes from what is
 * already on disk.
 */
#[Test]
final class AgentWorkspaceProbeTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-probe-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function bareProjectProposesNothing(): void
    {
        $layout = $this->probe();

        Assert::same($layout->target, ProjectConfig::DEFAULT_TARGET);
        Assert::same($layout->aliases, []);
        Assert::same($layout->collisions, []);
        Assert::false($layout->detected, 'a project with no agent directory has nothing to detect');
    }

    public function agentDirectoryInUseBecomesAnAlias(): void
    {
        // `.claude` without `.claude/skills`: the agent is in use and the
        // alias is free to create, which is the easiest case to get right.
        \mkdir($this->tmp . '/.claude', 0o777, true);

        $layout = $this->probe();

        Assert::same($layout->target, ProjectConfig::DEFAULT_TARGET);
        Assert::same($layout->aliases, ['.claude/skills']);
        Assert::true($layout->detected);
    }

    public function everyAgentDirectoryInUseIsProposed(): void
    {
        \mkdir($this->tmp . '/.claude', 0o777, true);
        \mkdir($this->tmp . '/.cursor', 0o777, true);

        $layout = $this->probe();

        Assert::same($layout->aliases, ['.claude/skills', '.cursor/skills']);
    }

    public function defaultTargetIsNeverProposedAsItsOwnAlias(): void
    {
        \mkdir($this->tmp . '/.agents', 0o777, true);

        $layout = $this->probe();

        Assert::same($layout->target, ProjectConfig::DEFAULT_TARGET);
        Assert::same($layout->aliases, []);
        Assert::true($layout->detected);
    }

    public function directoryWithItsOwnFilesIsReportedRatherThanProposed(): void
    {
        // Sync refuses to replace a real directory with a link, so proposing
        // this one as an alias would only produce a failing run later.
        \mkdir($this->tmp . '/.claude/skills/greeting', 0o777, true);

        $layout = $this->probe();

        Assert::same($layout->aliases, []);
        Assert::same($layout->collisions, ['.claude/skills']);
    }

    public function linkPointingAtAnotherAgentDirectoryMakesThatOneTheTarget(): void
    {
        // An existing layout: `.claude/skills` is already a link to
        // `.cursor/skills`, so the project treats the latter as the real
        // directory and the probe must not propose moving everything.
        \mkdir($this->tmp . '/.cursor/skills', 0o777, true);
        \mkdir($this->tmp . '/.claude', 0o777, true);
        if (!Filesystem::makeDirLink($this->tmp . '/.cursor/skills', $this->tmp . '/.claude/skills')) {
            Assert::same(
                $this->probe()->collisions,
                [],
                'links unavailable on this host — verifying the link-free layout has no collisions',
            );
            return;
        }

        $layout = $this->probe();

        Assert::same($layout->target, '.cursor/skills');
        Assert::same($layout->aliases, ['.claude/skills']);
        Assert::same($layout->collisions, []);
    }

    public function linkPointingOutsideTheKnownDirectoriesIsACollision(): void
    {
        // The link resolves somewhere the proposed target is not, and
        // replacing it would silently detach whatever it serves today.
        \mkdir($this->tmp . '/elsewhere', 0o777, true);
        \mkdir($this->tmp . '/.claude', 0o777, true);
        if (!Filesystem::makeDirLink($this->tmp . '/elsewhere', $this->tmp . '/.claude/skills')) {
            Assert::same(
                $this->probe()->aliases,
                ['.claude/skills'],
                'links unavailable on this host — verifying the plain directory is proposed',
            );
            return;
        }

        $layout = $this->probe();

        Assert::same($layout->target, ProjectConfig::DEFAULT_TARGET);
        Assert::same($layout->aliases, []);
        Assert::same($layout->collisions, ['.claude/skills']);
    }

    private function probe(): \LLM\Skills\Init\WorkspaceLayout
    {
        return (new AgentWorkspaceProbe())->probe(Path::create($this->tmp));
    }
}
