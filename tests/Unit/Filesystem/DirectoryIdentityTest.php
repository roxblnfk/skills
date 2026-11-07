<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Filesystem;

use Internal\Path;
use LLM\Skills\Filesystem\DirectoryIdentity;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
final class DirectoryIdentityTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-identity-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp . '/dir/inner', 0o777, true);
        \mkdir($this->tmp . '/other', 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function aMissingDirectoryHasNoIdentity(): void
    {
        Assert::null(DirectoryIdentity::of(Path::create($this->tmp . '/missing')));
    }

    public function matchesTheDirectoryUnderAnotherSpelling(): void
    {
        $identity = DirectoryIdentity::of(Path::create($this->tmp . '/dir'));

        Assert::true($identity?->matches($this->path('dir')) === true);
        Assert::true($identity?->matches($this->path('dir/inner/..')) === true);
    }

    public function matchesTheDirectoryUnderAnotherCaseOnACaseInsensitiveFileSystem(): void
    {
        if (!\is_dir($this->tmp . '/DIR')) {
            throw new SkipTest('the file system is case-sensitive');
        }

        Assert::true(DirectoryIdentity::of($this->path('dir'))?->matches($this->path('DIR')) === true);
    }

    public function doesNotMatchAnotherDirectory(): void
    {
        $identity = DirectoryIdentity::of($this->path('dir'));

        Assert::false($identity?->matches($this->path('other')) ?? true);
        Assert::false($identity?->matches($this->path('dir/inner')) ?? true);
        Assert::false($identity?->matches($this->path('missing')) ?? true);
    }

    private function path(string $relative): Path
    {
        return Path::create($this->tmp . '/' . $relative);
    }
}
