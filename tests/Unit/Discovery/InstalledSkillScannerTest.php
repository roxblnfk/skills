<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery;

use Internal\Path;
use LLM\Skills\Discovery\InstalledSkillScanner;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
final class InstalledSkillScannerTest
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

    public function returnsEmptyWhenTargetDirectoryDoesNotExist(): void
    {
        $result = (new InstalledSkillScanner())->scan(Path::create($this->tmp . '/missing'));

        Assert::same($result, []);
    }

    public function returnsEmptyWhenTargetIsEmpty(): void
    {
        $target = $this->tmp . '/target';
        \mkdir($target);

        $result = (new InstalledSkillScanner())->scan(Path::create($target));

        Assert::same($result, []);
    }

    public function listsEachSubdirectoryWithSkillMdAsAnInstalledSkill(): void
    {
        $target = $this->tmp . '/target';
        $this->installSkill($target, 'greeting');
        $this->installSkill($target, 'code-review');

        $result = (new InstalledSkillScanner())->scan(Path::create($target));

        Assert::same(\count($result), 2);
        $names = \array_map(static fn($s) => $s->name, $result);
        \sort($names);
        Assert::same($names, ['code-review', 'greeting']);
    }

    public function ignoresSubdirectoriesWithoutSkillMd(): void
    {
        // A directory under the target without SKILL.md is not a skill —
        // probably a user's WIP folder or unrelated content.
        $target = $this->tmp . '/target';
        $this->installSkill($target, 'real-skill');
        \mkdir($target . '/wip', 0o777, true);
        \file_put_contents($target . '/wip/notes.md', 'WIP');

        $result = (new InstalledSkillScanner())->scan(Path::create($target));

        Assert::same(\count($result), 1);
        Assert::same($result[0]->name, 'real-skill');
    }

    public function ignoresLooseFilesAtTargetRoot(): void
    {
        $target = $this->tmp . '/target';
        \mkdir($target, 0o777, true);
        \file_put_contents($target . '/README.md', 'top-level readme');
        $this->installSkill($target, 'real-skill');

        $result = (new InstalledSkillScanner())->scan(Path::create($target));

        Assert::same(\count($result), 1);
        Assert::same($result[0]->name, 'real-skill');
    }

    public function dirPointsAtTheInstalledSkillFolder(): void
    {
        $target = $this->tmp . '/target';
        $this->installSkill($target, 'greeting');

        $result = (new InstalledSkillScanner())->scan(Path::create($target));

        Assert::same(
            \str_replace('\\', '/', (string) $result[0]->dir),
            \str_replace('\\', '/', $target . '/greeting'),
        );
    }

    /**
     * @param non-empty-string $name
     */
    private function installSkill(string $target, string $name): void
    {
        $dir = $target . '/' . $name;
        \mkdir($dir, 0o777, true);
        \file_put_contents($dir . '/SKILL.md', "# {$name}");
    }
}
