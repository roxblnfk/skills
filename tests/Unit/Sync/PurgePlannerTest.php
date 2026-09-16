<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Sync;

use Internal\Path;
use LLM\Skills\Discovery\InstalledSkill;
use LLM\Skills\Discovery\Skill;
use LLM\Skills\Sync\PurgePlanner;
use Testo\Assert;
use Testo\Test;

/**
 * Tests the scoped / unscoped split in {@see PurgePlanner}. No filesystem is
 * involved: the planner is a pure set operation over names.
 */
#[Test]
final class PurgePlannerTest
{
    public function unscopedRunPurgesEverythingInstalled(): void
    {
        $purge = (new PurgePlanner())->plan(
            [$this->installed('greeting'), $this->installed('orphan')],
            [$this->incoming('greeting')],
            scoped: false,
        );

        Assert::same($this->names($purge), ['greeting', 'orphan']);
    }

    public function scopedRunPurgesOnlyWhatItWillWriteBack(): void
    {
        $purge = (new PurgePlanner())->plan(
            [$this->installed('greeting'), $this->installed('refactor')],
            [$this->incoming('greeting')],
            scoped: true,
        );

        Assert::same(
            $this->names($purge),
            ['greeting'],
            'a filtered run must not delete a skill it has no donor to restore from',
        );
    }

    public function scopedRunWithNothingInCommonPurgesNothing(): void
    {
        $purge = (new PurgePlanner())->plan(
            [$this->installed('greeting')],
            [$this->incoming('demo')],
            scoped: true,
        );

        Assert::same($purge, []);
    }

    public function emptyTargetYieldsNothingToPurge(): void
    {
        Assert::same((new PurgePlanner())->plan([], [$this->incoming('greeting')], scoped: false), []);
    }

    public function unscopedRunWithNoIncomingSkillsStillPurges(): void
    {
        // Every donor was dropped from the project: the only thing that can
        // clear the leftovers is a full wipe with nothing to put back.
        $purge = (new PurgePlanner())->plan([$this->installed('orphan')], [], scoped: false);

        Assert::same($this->names($purge), ['orphan']);
    }

    /**
     * @param non-empty-string $name
     */
    private function installed(string $name): InstalledSkill
    {
        return new InstalledSkill(name: $name, dir: Path::create('/target/' . $name));
    }

    /**
     * @param non-empty-string $name
     */
    private function incoming(string $name): Skill
    {
        return new Skill(
            name: $name,
            canonicalName: $name,
            sourceDir: Path::create('/donor/' . $name),
            packageName: 'acme/skills',
        );
    }

    /**
     * @param list<InstalledSkill> $purge
     *
     * @return list<non-empty-string>
     */
    private function names(array $purge): array
    {
        return \array_map(static fn(InstalledSkill $s): string => $s->name, $purge);
    }
}
