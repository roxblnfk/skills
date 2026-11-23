<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\BinSkillsRunner;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance tests for `composer skills:lint` and the standalone
 * `bin/skills lint`: the command validates any skill directory it is
 * pointed at, inside a Composer project or not, and reads no project configuration.
 */
#[Test]
final class SkillsLintTest
{
    private string $tmp;

    #[BeforeTest]
    public function createTempDir(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-validate-acceptance-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function removeTempDir(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function validInstalledDonorSkillsPass(): void
    {
        $process = ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:lint vendor/acme/skills-basic/.claude/skills',
            mustSucceed: false,
        );

        Assert::same($process->getExitCode(), 0, $process->getOutput() . $process->getErrorOutput());
        Assert::string($process->getOutput())->contains('0 error(s), 0 warning(s)');
    }

    public function invalidSkillFailsWithJsonFindings(): void
    {
        $this->writeSkill('Broken-Skill', "---\nname: Broken-Skill\n---\n\nSee [the guide](references/guide.md).\n");

        $process = ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            \sprintf('skills:lint --json %s', \escapeshellarg($this->tmp)),
            mustSucceed: false,
        );

        Assert::same($process->getExitCode(), 1, $process->getOutput() . $process->getErrorOutput());
        $findings = \json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($findings));
        $codes = \array_column($findings, 'code');
        foreach (['name-not-lowercase', 'description-missing', 'link-missing'] as $code) {
            Assert::true(\in_array($code, $codes, true), "missing $code in: " . $process->getOutput());
        }
    }

    public function standaloneBinaryValidatesWithoutAComposerProject(): void
    {
        $this->writeSkill('fine', "---\nname: fine\ndescription: A valid skill.\n---\n\nBody.\n");
        $this->writeSkill('mismatch', "---\nname: other\ndescription: Name differs from the directory.\n---\n\nBody.\n");

        $process = BinSkillsRunner::run(Path::create($this->tmp), 'lint');

        Assert::same($process->getExitCode(), 1, $process->getOutput() . $process->getErrorOutput());
        Assert::string($process->getOutput())
            ->contains('ERROR mismatch/SKILL.md:2: directory name "mismatch" must match skill name "other"')
            ->contains('1 error(s), 0 warning(s)');
    }

    private function writeSkill(string $dir, string $content): void
    {
        \mkdir("$this->tmp/$dir", 0o777, true);
        \file_put_contents("$this->tmp/$dir/SKILL.md", $content);
    }
}
