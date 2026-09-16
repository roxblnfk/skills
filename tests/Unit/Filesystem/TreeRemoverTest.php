<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Filesystem;

use LLM\Skills\Filesystem\TreeRemover;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests {@see TreeRemover} against a fresh temporary tree per test.
 *
 * The link cases are the ones that matter: a skill directory may contain a
 * link the user or a donor put there, and deleting through it would take out
 * content that lives outside the tree the caller named.
 */
#[Test]
final class TreeRemoverTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-rm-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function removesADirectoryTreeEntirely(): void
    {
        $dir = $this->makeTree('skill', [
            'SKILL.md' => '# Skill',
            'templates/one.md' => 'one',
            'templates/nested/two.md' => 'two',
        ]);

        Assert::true((new TreeRemover())->remove($dir));
        Assert::false(\is_dir($dir));
    }

    public function removesASingleFile(): void
    {
        $file = $this->tmp . '/loose.md';
        \file_put_contents($file, 'body');

        Assert::true((new TreeRemover())->remove($file));
        Assert::false(\is_file($file));
    }

    public function missingPathIsANoOp(): void
    {
        Assert::true((new TreeRemover())->remove($this->tmp . '/never-existed'));
    }

    public function stripsADirectoryLinkWithoutDeletingItsTarget(): void
    {
        $outside = $this->tmp . '/outside';
        \mkdir($outside, 0o777, true);
        \file_put_contents($outside . '/keep.md', 'precious');

        $dir = $this->makeTree('skill', ['SKILL.md' => '# Skill']);
        if (!Filesystem::makeDirLink($outside, $dir . '/link')) {
            throw new SkipTest('platform refuses directory links');
        }

        Assert::true((new TreeRemover())->remove($dir));
        Assert::false(\is_dir($dir));
        Assert::true(
            \is_file($outside . '/keep.md'),
            'content behind the link must survive removal of the tree containing it',
        );
    }

    public function stripsAFileLinkWithoutDeletingItsTarget(): void
    {
        $outside = $this->tmp . '/outside.md';
        \file_put_contents($outside, 'precious');

        $dir = $this->makeTree('skill', ['SKILL.md' => '# Skill']);
        if (!Filesystem::makeFileLink($outside, $dir . '/link.md')) {
            throw new SkipTest('platform refuses file symlinks');
        }

        Assert::true((new TreeRemover())->remove($dir));
        Assert::false(\is_dir($dir));
        Assert::true(\is_file($outside));
    }

    public function reportsFailureWhenTheTreeIsDeeperThanTheCap(): void
    {
        $dir = $this->makeTree('deep', ['SKILL.md' => '# Deep']);

        // 40 levels, past the remover's cap of 32.
        $chain = $dir;
        for ($i = 0; $i < 40; $i++) {
            $chain .= \DIRECTORY_SEPARATOR . 'd';
        }
        \mkdir($chain, 0o777, true);

        Assert::false((new TreeRemover())->remove($dir));
        Assert::true(\is_dir($dir), 'a tree the remover gave up on stays on disk for the caller to report');
    }

    /**
     * @param non-empty-string $name
     * @param array<non-empty-string, string> $files map of relative path → contents
     *
     * @return string absolute path of the created directory
     */
    private function makeTree(string $name, array $files): string
    {
        $dir = $this->tmp . '/' . $name;
        \mkdir($dir, 0o777, true);

        foreach ($files as $rel => $contents) {
            $full = $dir . '/' . $rel;
            $parent = \dirname($full);
            if (!\is_dir($parent)) {
                \mkdir($parent, 0o777, true);
            }
            \file_put_contents($full, $contents);
        }

        return $dir;
    }
}
