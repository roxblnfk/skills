<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery;

use Internal\Path;
use LLM\Skills\Discovery\SkillFrontmatterReader;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
final class SkillFrontmatterReaderTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-fm-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function readsKeyValuePairsFromLeadingFrontmatterBlock(): void
    {
        $dir = $this->skill('greeting', <<<'MD'
            ---
            name: greeting
            description: Reply with a friendly greeting.
            ---

            # Greeting

            Body content goes here.
            MD);

        $fm = (new SkillFrontmatterReader())->read($dir);

        Assert::same($fm, [
            'name' => 'greeting',
            'description' => 'Reply with a friendly greeting.',
        ]);
    }

    public function returnsNullWhenSkillMdHasNoFrontmatterBlock(): void
    {
        $dir = $this->skill('plain', "# Plain skill\n\nNo YAML header here.");

        Assert::null((new SkillFrontmatterReader())->read($dir));
    }

    public function returnsNullWhenSkillMdIsMissingEntirely(): void
    {
        // Directory exists but has no SKILL.md — e.g. an empty subdir.
        $dir = $this->tmp . '/orphan';
        \mkdir($dir, 0o777, true);

        Assert::null((new SkillFrontmatterReader())->read(Path::create($dir)));
    }

    public function returnsNullWhenFrontmatterBlockIsEmpty(): void
    {
        // `---\n---\n` is structurally valid but carries no keys.
        $dir = $this->skill('empty', "---\n---\n# Body\n");

        Assert::null((new SkillFrontmatterReader())->read($dir));
    }

    public function stripsSurroundingQuotesFromAValue(): void
    {
        // YAML allows quoting; the reader strips one layer of matching
        // " or ' so the caller sees the bare string.
        $dir = $this->skill('quoted', <<<'MD'
            ---
            description: "Reply, but with a quoted: colon inside"
            other: 'single-quoted'
            ---
            MD);

        $fm = (new SkillFrontmatterReader())->read($dir);

        Assert::same($fm, [
            'description' => 'Reply, but with a quoted: colon inside',
            'other' => 'single-quoted',
        ]);
    }

    public function ignoresLinesThatDoNotLookLikeKeyValuePairs(): void
    {
        // A line without `:` is dropped silently rather than failing the
        // whole read. The well-formed keys still come through.
        $dir = $this->skill('mixed', <<<'MD'
            ---
            name: mixed
            this is not a key-value line
            description: works anyway
            ---
            MD);

        $fm = (new SkillFrontmatterReader())->read($dir);

        Assert::same($fm, [
            'name' => 'mixed',
            'description' => 'works anyway',
        ]);
    }

    public function toleratesBomAtStartOfFile(): void
    {
        // A UTF-8 BOM is invisible to humans but would otherwise shift
        // the `---` off byte 0 and break the frontmatter detector.
        $dir = $this->skill(
            'bom',
            "\xEF\xBB\xBF---\nname: bom\ndescription: starts with BOM\n---\n",
        );

        $fm = (new SkillFrontmatterReader())->read($dir);

        Assert::same($fm, [
            'name' => 'bom',
            'description' => 'starts with BOM',
        ]);
    }

    public function returnsNullWhenFrontmatterDoesNotStartAtTheVeryTop(): void
    {
        // YAML frontmatter is positional: leading blank lines or
        // comments invalidate the block. The reader matches strictly.
        $dir = $this->skill('shifted', "# Title\n---\nname: shifted\n---\n");

        Assert::null((new SkillFrontmatterReader())->read($dir));
    }

    /**
     * @param non-empty-string $name
     */
    private function skill(string $name, string $contents): Path
    {
        $dir = $this->tmp . '/' . $name;
        \mkdir($dir, 0o777, true);
        \file_put_contents($dir . '/SKILL.md', $contents);

        return Path::create($dir);
    }
}
