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

    public function returnsEmptyResultForEmptyDonorList(): void
    {
        $result = (new SkillEnumerator())->enumerate([]);

        Assert::same($result->skills, []);
        Assert::same($result->warnings, []);
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
}
