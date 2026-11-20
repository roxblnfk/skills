<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Feature\Scripts;

use LLM\Skills\Tests\Testo\Filesystem;
use LLM\Skills\Tests\Testo\SkillScript;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Runs `validate-skill.php` against committed skill fixtures and generated inputs,
 * asserting on its exit code and the codes of its JSON findings.
 */
#[Test]
final class ValidateSkillScriptTest
{
    private const FIXTURES = __DIR__ . '/../../Fixture/Scripts/validate';

    private string $tmp;

    #[BeforeTest]
    public function createTempDir(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-validate-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function removeTempDir(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function validSkillPassesWithoutFindings(): void
    {
        $run = SkillScript::run(SkillScript::VALIDATE, ['valid-skill'], self::FIXTURES);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->findings(), [], $run->dump());
    }

    #[DataSet(['name-uppercase', 'name-not-lowercase'], 'uppercase')]
    #[DataSet(['name-leading-hyphen', 'name-hyphen-edge'], 'leading hyphen')]
    #[DataSet(['name-trailing-', 'name-hyphen-edge'], 'trailing hyphen')]
    #[DataSet(['name--double', 'name-double-hyphen'], 'double hyphen')]
    #[DataSet(['name-mismatch', 'name-dir-mismatch'], 'name differs from directory')]
    #[DataSet(['description-missing', 'description-missing'], 'description missing')]
    #[DataSet(['description-empty', 'description-empty'], 'description empty')]
    #[DataSet(['link-broken', 'link-missing'], 'broken link')]
    #[DataSet(['frontmatter-unclosed', 'frontmatter-unclosed'], 'unclosed frontmatter')]
    public function reportsAnError(string $skill, string $code): void
    {
        $run = SkillScript::run(SkillScript::VALIDATE, [$skill], self::FIXTURES);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode($code, 'ERROR', "$skill/SKILL.md"), 1, $run->dump());
    }

    #[DataSet(['name-uppercase', 2])]
    #[DataSet(['name--double', 2])]
    #[DataSet(['description-empty', 3])]
    #[DataSet(['link-broken', 8])]
    #[DataSet(['frontmatter-unclosed', 1])]
    public function pointsAtTheOffendingLine(string $skill, int $line): void
    {
        $run = SkillScript::run(SkillScript::VALIDATE, [$skill], self::FIXTURES);

        $errors = $run->atLevel('ERROR');
        Assert::notBlank($errors, $run->dump());
        Assert::same($errors[0]['line'], $line, $run->dump());
    }

    #[DataSet(['name-mismatch-package-root', 'name-dir-mismatch', 2], 'name mismatch at a package root')]
    #[DataSet(['description-angle-brackets', 'description-angle-brackets', 3], 'angle brackets')]
    #[DataSet(['unknown-key', 'unknown-key', 4], 'unknown key')]
    #[DataSet(['extension-key', 'extension-key', 4], 'extension key')]
    #[DataSet(['internal-top-level', 'internal-top-level', 4], 'top-level internal')]
    public function reportsOnlyAWarning(string $skill, string $code, int $line): void
    {
        $run = SkillScript::run(SkillScript::VALIDATE, [$skill], self::FIXTURES);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->atLevel('ERROR'), [], $run->dump());
        $warnings = $run->withCode($code, 'WARN', "$skill/SKILL.md");
        Assert::count($warnings, 1, $run->dump());
        Assert::same($warnings[0]['line'], $line);
    }

    public function suggestsTheSpecKeyForAMisspeltKey(): void
    {
        $run = SkillScript::run(SkillScript::VALIDATE, ['unknown-key'], self::FIXTURES);

        $warnings = $run->withCode('unknown-key', 'WARN');
        Assert::count($warnings, 1, $run->dump());
        Assert::string($warnings[0]['message'])->contains('"license"');
    }

    public function reportsADirectoryWithoutSkillMd(): void
    {
        $run = SkillScript::run(SkillScript::VALIDATE, ['no-skill-md'], self::FIXTURES);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('skill-md-missing', 'ERROR', 'no-skill-md'), 1, $run->dump());
    }

    public function warnsAboutALowercaseSkillFile(): void
    {
        $run = SkillScript::run(SkillScript::VALIDATE, ['wrong-case'], self::FIXTURES);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::count($run->withCode('skill-md-wrong-case', 'WARN', 'wrong-case/skill.md'), 1, $run->dump());
    }

    #[DataSet(['yaml-next-line'], 'value on the next deeper line')]
    #[DataSet(['yaml-block-scalar'], 'folded and literal block scalars')]
    #[DataSet(['links-ignored'], 'footnote, commented and indented links')]
    public function acceptsValidSyntax(string $skill): void
    {
        $run = SkillScript::run(SkillScript::VALIDATE, [$skill], self::FIXTURES);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->findings(), [], $run->dump());
    }

    public function reportsDirectoriesCollidingByCase(): void
    {
        $run = SkillScript::run(SkillScript::VALIDATE, ['collision'], self::FIXTURES);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('name-collision', 'ERROR', 'collision/one/Shared-Name'), 1, $run->dump());
        Assert::count($run->withCode('name-collision', 'ERROR', 'collision/two/shared-name'), 1, $run->dump());
    }

    public function rejectsANameLongerThan64Characters(): void
    {
        $name = \str_repeat('a', 65);
        $this->writeSkill($name, "---\nname: $name\ndescription: Fixture with an overlong name.\n---\n\nBody.\n");

        $run = SkillScript::run(SkillScript::VALIDATE, [$name], $this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('name-too-long', 'ERROR', "$name/SKILL.md"), 1, $run->dump());
    }

    public function acceptsANameOfExactly64Characters(): void
    {
        $name = \str_repeat('a', 64);
        $this->writeSkill($name, "---\nname: $name\ndescription: Fixture with a name at the limit.\n---\n\nBody.\n");

        $run = SkillScript::run(SkillScript::VALIDATE, [$name], $this->tmp);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->withCode('name-too-long'), [], $run->dump());
    }

    public function rejectsADescriptionLongerThan1024Characters(): void
    {
        $this->writeSkill('long-description', \sprintf(
            "---\nname: long-description\ndescription: %s\n---\n\nBody.\n",
            \str_repeat('x', 1025),
        ));

        $run = SkillScript::run(SkillScript::VALIDATE, ['long-description'], $this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('description-too-long', 'ERROR', 'long-description/SKILL.md'), 1, $run->dump());
    }

    public function reportsDeepNestingAsInvalidYamlAndKeepsValidatingOtherSkills(): void
    {
        $this->writeSkill('deep-nesting', \sprintf(
            "---\nname: deep-nesting\ndescription: %s%s\n---\n\nBody.\n",
            \str_repeat('[', 10000),
            \str_repeat(']', 10000),
        ));
        $this->writeSkill('mismatched', "---\nname: other\ndescription: Fixture validated after the deep one.\n---\n\nBody.\n");

        $run = SkillScript::run(SkillScript::VALIDATE, ['deep-nesting', 'mismatched'], $this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('yaml-invalid', 'ERROR', 'deep-nesting/SKILL.md'), 1, $run->dump());
        Assert::count($run->withCode('name-dir-mismatch', 'ERROR', 'mismatched/SKILL.md'), 1, $run->dump());
    }

    public function exitsWithUsageErrorOnUnknownOption(): void
    {
        $run = SkillScript::run(SkillScript::VALIDATE, ['--bogus'], self::FIXTURES, json: false);

        Assert::same($run->exitCode, 2);
        Assert::string($run->stderr)->contains('Usage:');
    }

    public function printsHelp(): void
    {
        $run = SkillScript::run(SkillScript::VALIDATE, ['--help'], self::FIXTURES, json: false);

        Assert::same($run->exitCode, 0);
        Assert::string($run->stdout)->contains('Usage:');
    }

    public function bundledSkillsAreValid(): void
    {
        $run = SkillScript::run(SkillScript::VALIDATE, ['resources/skills'], \dirname(__DIR__, 3));

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->atLevel('ERROR'), [], $run->dump());
    }

    private function writeSkill(string $dir, string $content): void
    {
        \mkdir("$this->tmp/$dir", 0o777, true);
        \file_put_contents("$this->tmp/$dir/SKILL.md", $content);
    }
}
