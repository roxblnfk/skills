<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Sync;

use Internal\Path;
use LLM\Skills\Discovery\InstalledSkill;
use LLM\Skills\Discovery\Skill;
use LLM\Skills\Sync\PurgePlanner;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Test;

/**
 * Tests the scoped / unscoped split in {@see PurgePlanner}. Pairing by exact
 * name needs no filesystem; pairing a differently cased name asks the file
 * system, so those tests build a real target.
 */
#[Test]
final class PurgePlannerTest
{
    public function scopedRunPairsADifferentlyCasedNameOnlyWhenItIsTheSameDirectory(): void
    {
        $target = \sys_get_temp_dir() . '/llm-skills-purge-' . \bin2hex(\random_bytes(6));
        \mkdir($target . '/Foo', 0o777, true);
        try {
            $installed = new InstalledSkill(name: 'Foo', dir: Path::create($target . '/Foo'));
            $caseInsensitive = \is_dir($target . '/foo');

            $purge = (new PurgePlanner())->plan([$installed], [$this->incoming('foo')], scoped: true);

            // A case-insensitive target writes `foo` into `Foo`; a case-sensitive
            // one keeps `Foo` as another skill's directory.
            Assert::same($this->names($purge), $caseInsensitive ? ['Foo'] : []);
        } finally {
            Filesystem::removeRecursive($target);
        }
    }

    public function sourceHoldersAreKeptOutOfThePurge(): void
    {
        $target = \sys_get_temp_dir() . '/llm-skills-purge-' . \bin2hex(\random_bytes(6));
        \mkdir($target . '/hand-made', 0o777, true);
        \mkdir($target . '/other', 0o777, true);
        try {
            $holder = new InstalledSkill(name: 'hand-made', dir: Path::create($target . '/hand-made'));
            $other = new InstalledSkill(name: 'other', dir: Path::create($target . '/other'));
            $source = new Skill(
                name: 'hand-made',
                canonicalName: 'hand-made',
                sourceDir: Path::create($target . '/hand-made'),
                packageName: 'project/hand-made',
            );

            [$deletable, $kept] = (new PurgePlanner())->partitionSourceHolders(
                [$holder, $other],
                [$source, $this->incoming('greeting')],
                Path::create($target),
            );

            Assert::same($this->names($deletable), ['other']);
            Assert::same($this->names($kept), ['hand-made']);
        } finally {
            Filesystem::removeRecursive($target);
        }
    }

    public function sourcesOutsideTheTargetKeepNothing(): void
    {
        [$deletable, $kept] = (new PurgePlanner())->partitionSourceHolders(
            [$this->installed('greeting')],
            [$this->incoming('greeting')],
            Path::create('/target'),
        );

        Assert::same($this->names($deletable), ['greeting']);
        Assert::same($kept, []);
    }

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
