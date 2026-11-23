<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\BinSkillsRunner;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Composer\WithSandboxExtras;
use LLM\Skills\Tests\Testo\Composer\WithSkillsJson;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance tests for `composer skills:validate` and the standalone `bin/skills validate`:
 * the CI check of a project's skills configuration, shipped skills and installed skills,
 * which exits 1 when any finding is an error.
 */
#[Test]
final class SkillsValidateTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';

    private string $tmp;

    #[BeforeTest]
    public function prepare(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-validate-project-acceptance-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function cleanUp(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        Filesystem::removeRecursive($this->tmp);
    }

    public function donorSkillsAreCheckedInVendorWithoutFailingTheRun(): void
    {
        // spiral/skills-demo, trusted as a built-in vendor, ships a SKILL.md without frontmatter.
        $process = $this->runValidate();

        Assert::same($process->getExitCode(), 0, $process->getOutput() . $process->getErrorOutput());
        Assert::string($process->getOutput())
            ->contains('WARN  vendor/spiral/skills-demo/skills/demo/SKILL.md:1: SKILL.md must start with YAML frontmatter')
            ->contains('0 error(s)');
        Assert::false(\is_dir(self::TARGET_DIR), 'validate must not install anything');
    }

    #[WithSandboxExtras(['trusted' => ['acme/skills-basic', 'clash/skills-conflict']])]
    public function twoTrustedDonorsShippingOneSkillFailTheRun(): void
    {
        // clash/skills-conflict ships "greeting", as acme/skills-basic does.
        $process = $this->runValidate();

        Assert::same($process->getExitCode(), 1, $process->getOutput() . $process->getErrorOutput());
        Assert::string($process->getOutput())
            ->contains('ERROR composer.json: skill "greeting" comes from several trusted donors');
    }

    public function anInstalledCopyIsCheckedAtItsDonor(): void
    {
        ComposerRunner::run(Path::create(Info::PROJECT_DIR), 'skills:update');

        $process = $this->runValidate();

        Assert::same($process->getExitCode(), 0, $process->getOutput() . $process->getErrorOutput());
        Assert::string($process->getOutput())->notContains('.agents/skills/demo');
    }

    #[WithSkillsJson(['target' => '../outside'])]
    public function aMalformedConfigFailsTheRun(): void
    {
        $process = $this->runValidate('--json');

        Assert::same($process->getExitCode(), 1, $process->getOutput() . $process->getErrorOutput());
        $findings = \json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($findings));
        Assert::same(\array_column($findings, 'code'), ['config-invalid'], $process->getOutput());
        Assert::same(\array_column($findings, 'file'), ['skills.json'], $process->getOutput());
    }

    public function standaloneBinaryValidatesTheShippedSkills(): void
    {
        \file_put_contents("$this->tmp/composer.json", '{"name": "acme/demo", "extra": {"skills": {"source": "skills"}}}');
        \mkdir("$this->tmp/skills/mismatch", 0o777, true);
        \file_put_contents("$this->tmp/skills/mismatch/SKILL.md", "---\nname: other\ndescription: Name differs from the directory.\n---\n\nBody.\n");
        \mkdir("$this->tmp/skills/assets", 0o777, true);

        $process = BinSkillsRunner::run(Path::create($this->tmp), 'validate');

        Assert::same($process->getExitCode(), 1, $process->getOutput() . $process->getErrorOutput());
        Assert::string($process->getOutput())
            ->contains('ERROR skills/mismatch/SKILL.md:2: directory name "mismatch" must match skill name "other"')
            ->contains('WARN  skills/assets: directory has no SKILL.md')
            ->contains('1 error(s), 1 warning(s)');
    }

    private function runValidate(string $args = ''): Process
    {
        return ComposerRunner::run(Path::create(Info::PROJECT_DIR), \trim('skills:validate ' . $args), mustSucceed: false);
    }
}
