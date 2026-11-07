<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Filesystem;

use Internal\Path;
use LLM\Skills\Filesystem\PathContainment;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
final class PathContainmentTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-contain-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp . '/outer/inner', 0o777, true);
        \mkdir($this->tmp . '/outer-sibling', 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function aDirectoryIsWithinItself(): void
    {
        Assert::true(PathContainment::isWithin($this->path('outer'), $this->path('outer')));
    }

    public function aSubdirectoryIsWithinItsParent(): void
    {
        Assert::true(PathContainment::isWithin($this->path('outer/inner'), $this->path('outer')));
        Assert::false(PathContainment::isWithin($this->path('outer'), $this->path('outer/inner')));
    }

    public function aSiblingSharingTheNamePrefixIsNotWithin(): void
    {
        Assert::false(PathContainment::isWithin($this->path('outer-sibling'), $this->path('outer')));
    }

    public function aMissingPathIsNeverWithin(): void
    {
        Assert::false(PathContainment::isWithin($this->path('outer/missing'), $this->path('outer')));
        Assert::false(PathContainment::isWithin($this->path('outer'), $this->path('missing')));
    }

    public function anUnnormalisedSpellingResolvesBeforeTheComparison(): void
    {
        Assert::true(PathContainment::isWithin($this->path('outer/inner/../inner'), $this->path('outer')));
    }

    public function aDifferentlyCasedSpellingMatchesOnACaseInsensitiveFileSystem(): void
    {
        if (!\is_dir($this->tmp . '/OUTER')) {
            throw new SkipTest('the file system is case-sensitive');
        }

        Assert::true(PathContainment::isWithin($this->path('OUTER/inner'), $this->path('outer')));
        Assert::true(PathContainment::isWithin($this->path('outer/inner'), $this->path('OUTER')));
    }

    public function differentlyCasedDirectoriesOnACaseSensitiveFileSystemAreNotTheSame(): void
    {
        \mkdir($this->tmp . '/cs');
        Filesystem::makeCaseSensitive($this->tmp . '/cs');
        \mkdir($this->tmp . '/cs/Foo');
        if (!@\mkdir($this->tmp . '/cs/foo') || \count(\scandir($this->tmp . '/cs') ?: []) !== 4) {
            throw new SkipTest('the file system folds case');
        }

        Assert::false(PathContainment::isSame($this->path('cs/Foo'), $this->path('cs/foo')));
        Assert::false(PathContainment::isWithin($this->path('cs/Foo'), $this->path('cs/foo')));
    }

    public function isSameHoldsOnlyForOneDirectory(): void
    {
        Assert::true(PathContainment::isSame($this->path('outer/inner/..'), $this->path('outer')));
        Assert::false(PathContainment::isSame($this->path('outer/inner'), $this->path('outer')));
    }

    private function path(string $relative): Path
    {
        return Path::create($this->tmp . '/' . $relative);
    }
}
