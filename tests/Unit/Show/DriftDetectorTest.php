<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Show;

use Internal\Path;
use LLM\Skills\Discovery\Skill;
use LLM\Skills\Show\DriftDetector;
use LLM\Skills\Sync\SyncEngine;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
final class DriftDetectorTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-drift-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function returnsFalseWhenDonorAndInstalledMatchExactly(): void
    {
        $donor = $this->makeDir('donor', ['SKILL.md' => '# Greeting']);
        $installed = $this->makeDir('installed', ['SKILL.md' => '# Greeting']);

        Assert::false((new DriftDetector())->differs($donor, $installed));
    }

    public function returnsTrueWhenAnyDonorFileContentDiffers(): void
    {
        $donor = $this->makeDir('donor', ['SKILL.md' => '# New version']);
        $installed = $this->makeDir('installed', ['SKILL.md' => '# Old version']);

        Assert::true((new DriftDetector())->differs($donor, $installed));
    }

    public function returnsTrueWhenADonorFileIsMissingInTarget(): void
    {
        $donor = $this->makeDir('donor', [
            'SKILL.md' => '# Greeting',
            'templates/suggestion.md' => 'template body',
        ]);
        // installed has SKILL.md but not the templates subtree → drift
        $installed = $this->makeDir('installed', ['SKILL.md' => '# Greeting']);

        Assert::true((new DriftDetector())->differs($donor, $installed));
    }

    public function returnsFalseWhenTargetHasExtraUserFilesNotInDonor(): void
    {
        // The detector must not flag user-added files as drift — sync is
        // non-destructive and never touches them.
        $donor = $this->makeDir('donor', ['SKILL.md' => '# Greeting']);
        $installed = $this->makeDir('installed', [
            'SKILL.md' => '# Greeting',
            'local-notes.md' => 'mine',
        ]);

        Assert::false((new DriftDetector())->differs($donor, $installed));
    }

    public function comparesNestedDonorTreesRecursively(): void
    {
        $donor = $this->makeDir('donor', [
            'SKILL.md' => '# Refactor',
            'templates/suggestion.md' => 'donor body',
        ]);
        $installed = $this->makeDir('installed', [
            'SKILL.md' => '# Refactor',
            'templates/suggestion.md' => 'stale body',
        ]);

        Assert::true((new DriftDetector())->differs($donor, $installed));
    }

    public function returnsFalseWhenNestedTreesMatch(): void
    {
        $donor = $this->makeDir('donor', [
            'SKILL.md' => '# Refactor',
            'templates/suggestion.md' => 'shared body',
        ]);
        $installed = $this->makeDir('installed', [
            'SKILL.md' => '# Refactor',
            'templates/suggestion.md' => 'shared body',
        ]);

        Assert::false((new DriftDetector())->differs($donor, $installed));
    }

    public function returnsTrueWhenDonorHasASubdirInstalledDoesNot(): void
    {
        $donor = $this->makeDir('donor', [
            'SKILL.md' => '# Refactor',
            'templates/suggestion.md' => 'body',
        ]);
        $installed = $this->makeDir('installed', ['SKILL.md' => '# Refactor']);

        Assert::true((new DriftDetector())->differs($donor, $installed));
    }

    public function ignoresTheTargetInsideADonorThatContainsIt(): void
    {
        $donor = $this->makeDir('project', [
            'SKILL.md' => '# Root',
            '.agents/skills/root/SKILL.md' => '# Root',
            '.agents/skills/other/SKILL.md' => '# Other',
        ]);
        $installed = Path::create((string) $donor . '/.agents/skills/root');
        \mkdir((string) $installed . '/.agents', 0o777, true);

        Assert::false((new DriftDetector())->differs($donor, $installed));
    }

    public function ignoresLinksTheSyncNeverCopies(): void
    {
        $donor = $this->makeDir('donor', ['SKILL.md' => '# Greeting']);
        $installed = $this->makeDir('installed', ['SKILL.md' => '# Greeting']);
        $this->makeDir('elsewhere', ['payload.md' => 'not copied']);
        if (!Filesystem::makeDirLink($this->tmp . '/elsewhere', (string) $donor . '/linked')) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        Assert::false((new DriftDetector())->differs($donor, $installed));
    }

    public function ignoresLinksInTheTargetTheSyncNeverWritesThrough(): void
    {
        $donor = $this->makeDir('donor', ['SKILL.md' => '# Greeting', 'scripts/run.sh' => 'donor']);
        $installed = $this->makeDir('installed', ['SKILL.md' => '# Greeting']);
        $this->makeDir('elsewhere', ['other.sh' => 'mine']);
        if (!Filesystem::makeDirLink($this->tmp . '/elsewhere', (string) $installed . '/scripts')) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        Assert::false((new DriftDetector())->differs($donor, $installed));
    }

    public function stopsAtTheDepthTheSyncCopiesTo(): void
    {
        $deep = \str_repeat('d/', SyncEngine::MAX_COPY_DEPTH) . 'beyond.md';
        $donor = $this->makeDir('donor', ['SKILL.md' => '# Greeting', $deep => 'never copied']);
        $installed = $this->makeDir('installed', ['SKILL.md' => '# Greeting']);

        $report = (new SyncEngine())->sync(
            [new Skill(name: 'installed', canonicalName: 'installed', sourceDir: $donor, packageName: 'acme/deep')],
            Path::create($this->tmp),
        );

        Assert::true($report->truncatedDirs !== [], 'the fixture must reach the copy depth cap');
        Assert::false((new DriftDetector())->differs($donor, $installed));
    }

    /**
     * @param array<non-empty-string, string> $files map of relative path → contents
     */
    private function makeDir(string $name, array $files): Path
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

        return Path::create($dir);
    }
}
