<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Validation;

use LLM\Skills\Validation\PathDisplay;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(PathDisplay::class)]
final class PathDisplayTest
{
    #[DataSet(['a/./b/../c', 'a/c'], 'dot segments')]
    #[DataSet(['../x', '../x'], 'relative climb kept')]
    #[DataSet(['/a/../../..', '/'], 'climb above a root stops there')]
    #[DataSet(['//server/share/skills', '//server/share/skills'], 'UNC prefix kept')]
    #[DataSet(['a/sp /b', 'a/sp /b'], 'spaces in a segment kept')]
    #[DataSet(['', '.'], 'empty')]
    public function normalizes(string $path, string $expected): void
    {
        Assert::same(PathDisplay::normalize($path), $expected);
    }

    #[DataSet(['c:\\x\\..\\..\\y', 'C:/y', 'a\\b'], 'drive letter')]
    #[DataSet(['\\\\server\\share\\skills', '//server/share/skills', 'a\\b'], 'UNC prefix')]
    public function treatsBackslashesAsSeparatorsOnlyOnWindows(string $windowsPath, string $normalized, string $name): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            Assert::same(PathDisplay::normalize($windowsPath), $normalized);
            Assert::same(PathDisplay::normalize($name), 'a/b');
        } else {
            Assert::same(PathDisplay::normalize($name), $name);
            Assert::false(PathDisplay::isAbsolute($windowsPath));
        }
    }

    #[DataSet(['/w/p/skills/a', 'skills/a'], 'inside')]
    #[DataSet(['/w/p', '.'], 'the directory itself')]
    #[DataSet(['/w/other', '/w/other'], 'outside stays absolute')]
    #[DataSet(['/w/project', '/w/project'], 'sibling sharing a prefix')]
    public function showsPathsRelativeToTheWorkingDirectory(string $path, string $expected): void
    {
        Assert::same((new PathDisplay('/w/p'))->show($path), $expected);
    }

    #[DataSet(['/w/p/a', '/w/p'], 'nested')]
    #[DataSet(['/a', '/'], 'under the root')]
    #[DataSet(['/', '/'], 'the root itself')]
    #[DataSet(['C:/a', 'C:/'], 'under a drive root')]
    #[DataSet(['//server/share/a', '//server/share'], 'under a UNC share')]
    public function findsTheParent(string $path, string $expected): void
    {
        Assert::same(PathDisplay::parent($path), $expected);
    }

    public function resolvesRelativePathsAgainstABase(): void
    {
        $paths = new PathDisplay('/w/p');

        Assert::same($paths->absolute('skills/a'), '/w/p/skills/a');
        Assert::same($paths->absolute('../x.md', '/w/p/skills/a'), '/w/p/skills/x.md');
        Assert::same($paths->absolute('/elsewhere', '/w/p'), '/elsewhere');
    }
}
