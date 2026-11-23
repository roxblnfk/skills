<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Feature;

use LLM\Skills\Tests\Testo\Filesystem;
use LLM\Skills\Tests\Testo\SkillValidation;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataSet;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Runs `validate` against generated projects, asserting on its exit code and the codes
 * of its JSON findings.
 */
#[Test]
final class ValidateCommandTest
{
    private const VALID = "---\nname: %s\ndescription: A valid skill.\n---\n\nBody.\n";
    private const MISNAMED = "---\nname: other\ndescription: Name differs from the directory.\n---\n\nBody.\n";

    private string $tmp;

    #[BeforeTest]
    public function createTempDir(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-validate-project-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function removeTempDir(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function anEmptyProjectPasses(): void
    {
        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->findings(), [], $run->dump());
    }

    public function aValidProjectPasses(): void
    {
        $this->composerJson(['extra' => ['skills' => ['source' => 'skills']]]);
        $this->file('skills.json', '{"target": ".agents/skills"}');
        $this->skill('skills/shipped', \sprintf(self::VALID, 'shipped'));
        $this->skill('.agents/skills/installed', \sprintf(self::VALID, 'installed'));

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->findings(), [], $run->dump());
    }

    #[DataSet(['{"target": 5}'], 'wrong type')]
    #[DataSet(['{"target": "../outside"}'], 'target outside the project')]
    #[DataSet(['{"aliases": [".agents/skills"]}'], 'alias equal to the target')]
    #[DataSet(['{"unknown": true}'], 'unknown key')]
    #[DataSet(['{'], 'invalid JSON')]
    public function reportsAMalformedSkillsJson(string $content): void
    {
        $this->file('skills.json', $content);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('config-invalid', 'ERROR', 'skills.json'), 1, $run->dump());
    }

    public function reportsAMalformedLocalLayerAgainstItsFile(): void
    {
        $this->file('skills.json', '{}');
        $this->file('skills.local.json', '{"target": 5}');

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('config-invalid', 'ERROR', 'skills.local.json'), 1, $run->dump());
    }

    public function reportsAMalformedInlineConfigAgainstComposerJson(): void
    {
        $this->composerJson(['extra' => ['skills' => ['target' => 5]]]);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('config-invalid', 'ERROR', 'composer.json'), 1, $run->dump());
    }

    #[DataSet(['{'], 'invalid JSON')]
    #[DataSet(['[]'], 'not an object')]
    public function reportsAnUnreadableComposerJson(string $content): void
    {
        $this->file('composer.json', $content);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('composer-json-invalid', 'ERROR', 'composer.json'), 1, $run->dump());
    }

    public function deprecatedKeysWarnWithoutFailing(): void
    {
        $this->composerJson(['extra' => ['skills' => ['trusted' => ['acme/*']]]]);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::count($run->withCode('config-deprecated-key', 'WARN', 'composer.json'), 1, $run->dump());
        Assert::count($run->withCode('config-inline-legacy', 'INFO', 'composer.json'), 1, $run->dump());
    }

    public function warnsAboutInlineKeysASkillsJsonShadows(): void
    {
        $this->composerJson(['extra' => ['skills' => ['target' => 'elsewhere']]]);
        $this->file('skills.json', '{}');

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::count($run->withCode('config-inline-ignored', 'WARN', 'composer.json'), 1, $run->dump());
    }

    #[DataSet([['source' => '/abs']], 'absolute source')]
    #[DataSet([['source' => '../up']], 'source outside the package')]
    #[DataSet([['source' => []]], 'empty source list')]
    #[DataSet([['sources' => [['from' => 'dir', 'path' => 'x']]]], 'path-only external source')]
    public function reportsAMalformedDistributionDeclaration(array $skills): void
    {
        $this->composerJson(['extra' => ['skills' => $skills]]);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('distribution-invalid', 'ERROR', 'composer.json'), 1, $run->dump());
    }

    public function reportsAMissingSourceDirectory(): void
    {
        $this->composerJson(['extra' => ['skills' => ['source' => 'skills']]]);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('source-missing', 'ERROR', 'skills'), 1, $run->dump());
    }

    public function reportsTheLayoutOfASourceDirectory(): void
    {
        $this->composerJson(['extra' => ['skills' => ['source' => 'skills']]]);
        $this->skill('skills/fine', \sprintf(self::VALID, 'fine'));
        $this->skill('skills/.draft', \sprintf(self::VALID, '.draft'));
        \mkdir("$this->tmp/skills/assets", 0o777, true);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::count($run->withCode('source-subdir-not-skill', 'WARN', 'skills/assets'), 1, $run->dump());
        Assert::count($run->withCode('hidden-dir-skipped', 'INFO', 'skills/.draft'), 1, $run->dump());
    }

    public function validatesShippedAndInstalledSkills(): void
    {
        $this->composerJson(['extra' => ['skills' => ['source' => 'skills']]]);
        $this->skill('skills/shipped', self::MISNAMED);
        $this->skill('.agents/skills/installed', self::MISNAMED);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('name-dir-mismatch', 'ERROR', 'skills/shipped/SKILL.md'), 1, $run->dump());
        Assert::count($run->withCode('name-dir-mismatch', 'ERROR', '.agents/skills/installed/SKILL.md'), 1, $run->dump());
    }

    public function aSkillBothShippedAndInstalledIsValidatedOnce(): void
    {
        $this->composerJson(['extra' => ['skills' => ['source' => 'skills']]]);
        $this->skill('skills/shared', self::MISNAMED);
        \mkdir("$this->tmp/.agents/skills", 0o777, true);
        if (!Filesystem::makeDirLink("$this->tmp/skills/shared", "$this->tmp/.agents/skills/shared")) {
            throw new SkipTest('platform refuses both symlink and junction creation');
        }

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('name-dir-mismatch'), 1, $run->dump());
        Assert::count($run->withCode('name-dir-mismatch', 'ERROR', 'skills/shared/SKILL.md'), 1, $run->dump());
    }

    public function aTargetInsideTheSourceIsValidatedOnce(): void
    {
        $this->composerJson(['extra' => ['skills' => ['source' => '.agents/skills']]]);
        $this->skill('.agents/skills/shared', self::MISNAMED);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('name-dir-mismatch'), 1, $run->dump());
    }

    public function reportsASkillShippedFromTwoSources(): void
    {
        $this->composerJson(['extra' => ['skills' => ['source' => ['a', 'b']]]]);
        $this->skill('a/same', \sprintf(self::VALID, 'same'));
        $this->skill('b/same', \sprintf(self::VALID, 'same'));

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('duplicate-shipped-skill', 'ERROR', 'a/same'), 1, $run->dump());
        Assert::count($run->withCode('duplicate-shipped-skill', 'ERROR', 'b/same'), 1, $run->dump());
    }

    #[DataSet([['skills', './skills']], 'dot prefix')]
    #[DataSet([['skills', 'skills/']], 'trailing slash')]
    public function reportsTwoSourceEntriesForOneDirectory(array $source): void
    {
        $this->composerJson(['extra' => ['skills' => ['source' => $source]]]);
        $this->skill('skills/fine', \sprintf(self::VALID, 'fine'));

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('source-duplicate', 'ERROR', 'skills'), 1, $run->dump());
    }

    public function aNonSkillSubdirectoryClashesWithANamesakeSkill(): void
    {
        $this->composerJson(['extra' => ['skills' => ['source' => ['a', 'b']]]]);
        $this->file('a/same/README.md', 'Not a skill.');
        $this->skill('b/same', \sprintf(self::VALID, 'same'));

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('duplicate-shipped-skill', 'ERROR', 'a/same'), 1, $run->dump());
    }

    public function aRefusedNonSkillSubdirectoryIsNoDuplicate(): void
    {
        $this->composerJson(['extra' => ['skills' => ['source' => ['a', 'b']]]]);
        $this->file('a/x~1/README.md', 'Not a skill.');
        $this->file('b/x~1/README.md', 'Not a skill.');
        $this->skill('a/fine', \sprintf(self::VALID, 'fine'));

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->findings(), [], $run->dump());
    }

    public function anInternalNamesakeIsNoDuplicate(): void
    {
        $this->composerJson(['extra' => ['skills' => ['source' => ['a', 'b']]]]);
        $this->skill('a/same', \sprintf(self::VALID, 'same'));
        $this->skill('b/same', "---\nname: same\ndescription: A valid skill.\nmetadata:\n  internal: true\n---\n\nBody.\n");

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->withCode('duplicate-shipped-skill'), [], $run->dump());
        Assert::same($run->exitCode, 0, $run->dump());
    }

    public function aHiddenInstalledDirectoryIsSkipped(): void
    {
        $this->skill('.agents/skills/.parked', self::MISNAMED);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::count($run->withCode('hidden-dir-skipped', 'INFO', '.agents/skills/.parked'), 1, $run->dump());
    }

    public function aSourceClimbingAboveTheFilesystemRootIsReported(): void
    {
        $this->composerJson(['extra' => ['skills' => ['source' => \str_repeat('../', 40) . 'x']]]);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('distribution-invalid', 'ERROR', 'composer.json'), 1, $run->dump());
    }

    public function aTargetClimbingAboveTheFilesystemRootIsReported(): void
    {
        $this->file('skills.json', \json_encode(['target' => \str_repeat('../', 40) . 'x'], \JSON_UNESCAPED_SLASHES));

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('config-invalid', 'ERROR', 'skills.json'), 1, $run->dump());
    }

    public function twoDonorsShippingOneSkillFailTheRun(): void
    {
        $this->skillsJson(['sources' => [['from' => 'dir', 'path' => './a'], ['from' => 'dir', 'path' => './b']]]);
        $this->skill('a/same', \sprintf(self::VALID, 'same'));
        $this->skill('b/same', \sprintf(self::VALID, 'same'));

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('skill-conflict', 'ERROR', 'skills.json'), 1, $run->dump());
    }

    public function aDirSourceSkillKeepsItsErrors(): void
    {
        // A dir source is the project's own directory; third-party donor skills only warn,
        // which the acceptance suite covers with installed packages.
        $this->skillsJson(['sources' => [['from' => 'dir', 'path' => './donor']]]);
        $this->skill('donor/mine', self::MISNAMED);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('name-dir-mismatch', 'ERROR', 'donor/mine/SKILL.md'), 1, $run->dump());
    }

    public function aMissingDirSourceFailsTheRun(): void
    {
        $this->skillsJson(['sources' => [['from' => 'dir', 'path' => './gone']]]);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('source-failed', 'ERROR', 'skills.json'), 1, $run->dump());
    }

    public function anInstalledCopyOfADonorSkillIsNotCheckedAgain(): void
    {
        $this->skillsJson(['sources' => [['from' => 'dir', 'path' => './donor']]]);
        $this->skill('donor/theirs', self::MISNAMED);
        $this->skill('.agents/skills/theirs', self::MISNAMED);

        $run = SkillValidation::runProject($this->tmp);

        Assert::count($run->withCode('name-dir-mismatch'), 1, $run->dump());
        Assert::count($run->withCode('name-dir-mismatch', file: 'donor/theirs/SKILL.md'), 1, $run->dump());
    }

    public function aSkillPutInTheTargetByHandFailsTheRun(): void
    {
        $this->skillsJson(['sources' => [['from' => 'dir', 'path' => './donor']]]);
        $this->skill('donor/theirs', \sprintf(self::VALID, 'theirs'));
        $this->skill('.agents/skills/mine', self::MISNAMED);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('name-dir-mismatch', 'ERROR', '.agents/skills/mine/SKILL.md'), 1, $run->dump());
    }

    public function withoutFetchingRemoteDonorsAnUnknownInstalledSkillOnlyWarns(): void
    {
        $this->skillsJson(['sources' => [['from' => 'github', 'package' => 'acme/skills']]]);
        $this->skill('.agents/skills/unknown', self::MISNAMED);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::count($run->withCode('donors-not-fetched', 'INFO', 'skills.json'), 1, $run->dump());
        Assert::count($run->withCode('name-dir-mismatch', 'WARN', '.agents/skills/unknown/SKILL.md'), 1, $run->dump());
    }

    public function installedSkillsSkipWhenTheConfigIsMalformed(): void
    {
        $this->file('skills.json', '{"target": 5}');
        $this->skill('.agents/skills/installed', self::MISNAMED);

        $run = SkillValidation::runProject($this->tmp);

        Assert::same($run->withCode('name-dir-mismatch'), [], $run->dump());
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function composerJson(array $manifest): void
    {
        $this->file('composer.json', \json_encode($manifest, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function skillsJson(array $config): void
    {
        $this->file('skills.json', \json_encode($config, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    private function skill(string $dir, string $content): void
    {
        $this->file("$dir/SKILL.md", $content);
    }

    private function file(string $path, string $content): void
    {
        $full = "$this->tmp/$path";
        \is_dir(\dirname($full)) or \mkdir(\dirname($full), 0o777, true);
        \file_put_contents($full, $content);
    }
}
