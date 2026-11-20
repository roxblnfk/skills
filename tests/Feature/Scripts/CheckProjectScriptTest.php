<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Feature\Scripts;

use LLM\Skills\Tests\Testo\Filesystem;
use LLM\Skills\Tests\Testo\SkillScript;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataSet;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Runs `check-project.php` against throw-away project directories, asserting on its
 * exit code and the codes of its JSON findings.
 */
#[Test]
final class CheckProjectScriptTest
{
    private string $tmp;

    #[BeforeTest]
    public function createProjectDir(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-check-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function removeProjectDir(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function reportsInvalidJson(): void
    {
        $this->write('skills.json', '{"target": ".agents/skills",}');

        $run = $this->check();

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('config-invalid-json', 'ERROR', 'skills.json'), 1, $run->dump());
    }

    public function reportsAByteOrderMark(): void
    {
        $this->write('skills.json', "\xEF\xBB\xBF{\"target\": \".agents/skills\"}");

        $run = $this->check();

        Assert::same($run->exitCode, 1, $run->dump());
        $errors = $run->withCode('config-bom', 'ERROR', 'skills.json');
        Assert::count($errors, 1, $run->dump());
        Assert::same($errors[0]['line'], 1);
    }

    public function reportsAnUnknownTopLevelKey(): void
    {
        $this->write('skills.json', "{\n    \"target\": \".agents/skills\",\n    \"aliasses\": []\n}");

        $run = $this->check();

        Assert::same($run->exitCode, 1, $run->dump());
        $errors = $run->withCode('config-unknown-key', 'ERROR', 'skills.json');
        Assert::count($errors, 1, $run->dump());
        Assert::same($errors[0]['line'], 3);
    }

    public function rejectsABareStarInComposerTrust(): void
    {
        $this->writeJson('skills.json', ['dependencies' => ['composer' => ['trusted' => ['*']]]]);

        $run = $this->check();

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('trust-pattern-star', 'ERROR', 'skills.json'), 1, $run->dump());
    }

    public function acceptsABareStarInNpmTrust(): void
    {
        $this->writeJson('skills.json', ['dependencies' => ['npm' => ['trusted' => ['*']]]]);

        $run = $this->check();

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->atLevel('ERROR'), [], $run->dump());
    }

    #[DataSet(['^1'])]
    #[DataSet(['~1'])]
    #[DataSet(['=1.4'])]
    #[DataSet(['^v1.2'])]
    #[DataSet(['=2.0.0-beta1'])]
    #[DataSet(['main'])]
    #[DataSet(['3f2a9c1d8e7b6a5f4e3d2c1b0a9f8e7d6c5b4a39'])]
    public function acceptsASupportedRef(string $ref): void
    {
        $this->writeJson('skills.json', ['sources' => [['from' => 'github', 'package' => 'acme/skills', 'ref' => $ref]]]);

        $run = $this->check();

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->atLevel('ERROR'), [], $run->dump());
    }

    #[DataSet(['1.*'])]
    #[DataSet(['>=1.0'])]
    #[DataSet(['^1 || ^2'])]
    #[DataSet(['!main'])]
    public function rejectsAnUnsupportedRef(string $ref): void
    {
        $this->writeJson('skills.json', ['sources' => [['from' => 'github', 'package' => 'acme/skills', 'ref' => $ref]]]);

        $run = $this->check();

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('source-ref-unsupported', 'ERROR', 'skills.json'), 1, $run->dump());
    }

    public function rejectsALocalAliasEqualToTheSharedTarget(): void
    {
        $this->writeJson('skills.json', ['target' => 'custom/skills']);
        $this->writeJson('skills.local.json', ['aliases' => ['custom/skills']]);

        $run = $this->check();

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('alias-equals-target', 'ERROR', 'skills.local.json'), 1, $run->dump());
    }

    public function acceptsALocalAliasAtTheDefaultTargetWhenTheSharedTargetDiffers(): void
    {
        $this->writeJson('skills.json', ['target' => 'custom/skills']);
        $this->writeJson('skills.local.json', ['aliases' => ['.agents/skills']]);

        $run = $this->check();

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->withCode('alias-equals-target'), [], $run->dump());
    }

    public function rejectsThePackageRootAsSkillsSource(): void
    {
        $this->writeJson('composer.json', ['name' => 'acme/package', 'extra' => ['skills' => ['source' => '.']]]);

        $run = $this->check();

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('source-invalid', 'ERROR', 'composer.json'), 1, $run->dump());
    }

    public function acceptsADirSourceWithNullUrl(): void
    {
        \mkdir("$this->tmp/local-skills");
        $this->writeJson('skills.json', ['sources' => [['from' => 'dir', 'path' => './local-skills', 'url' => null]]]);

        $run = $this->check();

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->atLevel('ERROR'), [], $run->dump());
    }

    public function rejectsAnAliasThatIsARealDirectory(): void
    {
        \mkdir("$this->tmp/.claude/skills", 0o777, true);
        $this->writeJson('skills.json', ['aliases' => ['.claude/skills']]);

        $run = $this->check();

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('alias-real-directory', 'ERROR', '.claude/skills'), 1, $run->dump());
    }

    public function rejectsTheSameSkillShippedFromTwoFolders(): void
    {
        $this->writeJson('composer.json', ['name' => 'acme/package']);
        $skill = "---\nname: shared\ndescription: Skill shipped from two discovery folders.\n---\n\nBody.\n";
        $this->write('skills/shared/SKILL.md', $skill);
        $this->write('.claude/skills/shared/SKILL.md', $skill);

        $run = $this->check();

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('duplicate-shipped-skill', 'ERROR', 'skills/shared'), 1, $run->dump());
        Assert::count($run->withCode('duplicate-shipped-skill', 'ERROR', '.claude/skills/shared'), 1, $run->dump());
    }

    public function warnsAboutATrackedTarget(): void
    {
        $this->initGit();
        $this->writeJson('skills.json', ['target' => '.agents/skills']);
        $this->write('.gitignore', "/skills.local.json\n");
        $this->write('.agents/skills/demo/SKILL.md', "---\nname: demo\ndescription: Installed skill.\n---\n");
        $this->git('add', '.');

        $run = $this->check();

        Assert::count($run->withCode('target-tracked', 'WARN', '.agents/skills'), 1, $run->dump());
    }

    public function warnsWhenLocalConfigIsNotIgnored(): void
    {
        $this->initGit();
        $this->writeJson('skills.json', ['target' => '.agents/skills']);

        $run = $this->check();

        Assert::count($run->withCode('local-config-not-ignored', 'WARN'), 1, $run->dump());
    }

    public function acceptsAnIgnoredLocalConfig(): void
    {
        $this->initGit();
        $this->writeJson('skills.json', ['target' => '.agents/skills']);
        $this->write('.gitignore', "/skills.local.json\n");

        $run = $this->check();

        Assert::same($run->withCode('local-config-not-ignored'), [], $run->dump());
        Assert::same($run->withCode('local-config-tracked'), [], $run->dump());
    }

    public function exitsWithUsageErrorOnAPositionalArgument(): void
    {
        $run = SkillScript::run(SkillScript::CHECK_PROJECT, ['somewhere'], $this->tmp, json: false);

        Assert::same($run->exitCode, 2);
        Assert::string($run->stderr)->contains('Usage:');
    }

    public function printsHelp(): void
    {
        $run = SkillScript::run(SkillScript::CHECK_PROJECT, ['--help'], $this->tmp, json: false);

        Assert::same($run->exitCode, 0);
        Assert::string($run->stdout)->contains('Usage:');
    }

    private function check(): SkillScript
    {
        return SkillScript::run(SkillScript::CHECK_PROJECT, [], $this->tmp);
    }

    private function write(string $path, string $content): void
    {
        $file = "$this->tmp/$path";
        if (!\is_dir(\dirname($file))) {
            \mkdir(\dirname($file), 0o777, true);
        }
        \file_put_contents($file, $content);
    }

    private function writeJson(string $path, array $data): void
    {
        $this->write($path, \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
    }

    private function initGit(): void
    {
        $probe = new Process(['git', '--version']);
        $probe->run();
        if (!$probe->isSuccessful()) {
            throw new SkipTest('git is not available');
        }
        $this->git('init', '--quiet');
    }

    private function git(string ...$args): void
    {
        $process = new Process(['git', '-C', $this->tmp, ...$args]);
        $process->mustRun();
    }
}
