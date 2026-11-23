<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Feature;

use LLM\Skills\Tests\Testo\Filesystem;
use LLM\Skills\Tests\Testo\SkillValidation;
use LLM\Skills\Validation\FindingReport;
use LLM\Skills\Validation\LintRunner;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Runs `lint` against committed skill fixtures and generated inputs,
 * asserting on its exit code and the codes of its JSON findings.
 */
#[Test]
final class LintCommandTest
{
    private const FIXTURES = __DIR__ . '/../Fixture/Lint';

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
        $run = SkillValidation::run(['valid-skill'], self::FIXTURES);

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
        $run = SkillValidation::run([$skill], self::FIXTURES);

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
        $run = SkillValidation::run([$skill], self::FIXTURES);

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
        $run = SkillValidation::run([$skill], self::FIXTURES);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->atLevel('ERROR'), [], $run->dump());
        $warnings = $run->withCode($code, 'WARN', "$skill/SKILL.md");
        Assert::count($warnings, 1, $run->dump());
        Assert::same($warnings[0]['line'], $line);
    }

    public function suggestsTheSpecKeyForAMisspeltKey(): void
    {
        $run = SkillValidation::run(['unknown-key'], self::FIXTURES);

        $warnings = $run->withCode('unknown-key', 'WARN');
        Assert::count($warnings, 1, $run->dump());
        Assert::string($warnings[0]['message'])->contains('"license"');
    }

    public function reportsADirectoryWithoutSkillMd(): void
    {
        $run = SkillValidation::run(['no-skill-md'], self::FIXTURES);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('skill-md-missing', 'ERROR', 'no-skill-md'), 1, $run->dump());
    }

    public function warnsAboutALowercaseSkillFile(): void
    {
        $run = SkillValidation::run(['wrong-case'], self::FIXTURES);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::count($run->withCode('skill-md-wrong-case', 'WARN', 'wrong-case/skill.md'), 1, $run->dump());
    }

    #[DataSet(['yaml-next-line'], 'value on the next deeper line')]
    #[DataSet(['yaml-block-scalar'], 'folded and literal block scalars')]
    #[DataSet(['links-ignored'], 'footnote, commented and indented links')]
    public function acceptsValidSyntax(string $skill): void
    {
        $run = SkillValidation::run([$skill], self::FIXTURES);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->findings(), [], $run->dump());
    }

    public function reportsDirectoriesCollidingByCase(): void
    {
        $run = SkillValidation::run(['collision'], self::FIXTURES);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('name-collision', 'ERROR', 'collision/one/Shared-Name'), 1, $run->dump());
        Assert::count($run->withCode('name-collision', 'ERROR', 'collision/two/shared-name'), 1, $run->dump());
    }

    public function rejectsANameLongerThan64Characters(): void
    {
        $name = \str_repeat('a', 65);
        $this->writeSkill($name, "---\nname: $name\ndescription: Fixture with an overlong name.\n---\n\nBody.\n");

        $run = SkillValidation::run([$name], $this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('name-too-long', 'ERROR', "$name/SKILL.md"), 1, $run->dump());
    }

    public function acceptsANameOfExactly64Characters(): void
    {
        $name = \str_repeat('a', 64);
        $this->writeSkill($name, "---\nname: $name\ndescription: Fixture with a name at the limit.\n---\n\nBody.\n");

        $run = SkillValidation::run([$name], $this->tmp);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->withCode('name-too-long'), [], $run->dump());
    }

    public function rejectsADescriptionLongerThan1024Characters(): void
    {
        $this->writeSkill('long-description', \sprintf(
            "---\nname: long-description\ndescription: %s\n---\n\nBody.\n",
            \str_repeat('x', 1025),
        ));

        $run = SkillValidation::run(['long-description'], $this->tmp);

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

        $run = SkillValidation::run(['deep-nesting', 'mismatched'], $this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('yaml-invalid', 'ERROR', 'deep-nesting/SKILL.md'), 1, $run->dump());
        Assert::count($run->withCode('name-dir-mismatch', 'ERROR', 'mismatched/SKILL.md'), 1, $run->dump());
    }

    public function warnsWhenTheDefaultPathHoldsNoSkill(): void
    {
        $run = SkillValidation::run([], $this->tmp);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::count($run->withCode('skill-md-missing', 'WARN', '.'), 1, $run->dump());
    }

    public function reportsAMissingPath(): void
    {
        $run = SkillValidation::run(['absent'], $this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('path-missing', 'ERROR', 'absent'), 1, $run->dump());
    }

    public function aLinkClimbingAboveTheRootIsAMissingLinkNotAFailedSkill(): void
    {
        $this->writeSkill('deep-link', \sprintf(
            "---\nname: deep-link\ndescription: Fixture.\n---\n\n[x](%sx.md) and [y](missing.md)\n",
            \str_repeat('../', 64),
        ));

        $run = SkillValidation::run(['deep-link'], $this->tmp);

        Assert::same($run->withCode('validation-failed'), [], $run->dump());
        Assert::count($run->withCode('link-missing', 'ERROR', 'deep-link/SKILL.md'), 2, $run->dump());
    }

    public function anArgumentClimbingAboveTheRootIsReportedNotThrown(): void
    {
        $run = SkillValidation::run([\str_repeat('../', 64) . 'absent'], $this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('path-missing', 'ERROR'), 1, $run->dump());
    }

    public function skipsHiddenSkillsInASearchedDirectory(): void
    {
        $this->writeSkill('skills/shipped', "---\nname: shipped\ndescription: Fixture.\n---\n\nBody.\n");
        $this->writeSkill('skills/.drafts', "---\nname: drafts\n---\n");

        $run = SkillValidation::run(['skills'], $this->tmp);

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::count($run->withCode('hidden-dir-skipped', 'INFO', 'skills/.drafts'), 1, $run->dump());
        Assert::same($run->atLevel('ERROR'), [], $run->dump());
    }

    public function aNamedDirectoryHoldingOnlyHiddenSkillsFails(): void
    {
        $this->writeSkill('skills/.drafts', "---\nname: drafts\ndescription: Fixture.\n---\n\nBody.\n");

        $run = SkillValidation::run(['skills'], $this->tmp);

        Assert::same($run->exitCode, 1, $run->dump());
        Assert::count($run->withCode('skill-md-missing', 'ERROR', 'skills'), 1, $run->dump());
    }

    public function validatesAHiddenSkillNamedExplicitly(): void
    {
        $this->writeSkill('.drafts', "---\nname: drafts\ndescription: Fixture.\n---\n\nBody.\n");

        $run = SkillValidation::run(['.drafts'], $this->tmp);

        Assert::count($run->withCode('dir-name-refused', 'ERROR', '.drafts'), 1, $run->dump());
    }

    public function reportsLinkFindingsAgainstTheSkillFileAsNamedOnDisk(): void
    {
        \mkdir("$this->tmp/lower");
        \file_put_contents("$this->tmp/lower/skill.md", "---\nname: lower\ndescription: Fixture.\n---\n\n[x](missing.md)\n");

        $run = SkillValidation::run(['lower'], $this->tmp);

        Assert::count($run->withCode('link-missing', 'ERROR', 'lower/skill.md'), 1, $run->dump());
    }

    public function warnsAboutANonAsciiDirectoryName(): void
    {
        $this->writeSkill('café', "---\nname: café\ndescription: Fixture.\n---\n\nBody.\n");

        $run = SkillValidation::run(['café'], $this->tmp);

        Assert::count($run->withCode('dir-name-non-ascii', 'WARN', 'café'), 1, $run->dump());
        Assert::same($run->withCode('dir-name-refused'), [], $run->dump());
    }

    public function oneDirectoryUnderTwoSpellingsIsNoCollision(): void
    {
        $this->writeSkill('same', "---\nname: same\ndescription: Fixture.\n---\n\nBody.\n");
        if (!\is_dir("$this->tmp/SAME")) {
            Assert::true(true, 'case-sensitive filesystem: two spellings are two directories');

            return;
        }

        $run = SkillValidation::run(['same', 'SAME'], $this->tmp);

        Assert::same($run->withCode('name-collision'), [], $run->dump());
    }

    #[DataSet([4096, false], 'closing delimiter ends at the limit')]
    #[DataSet([4097, true], 'closing delimiter ends past the limit')]
    public function warnsWhenThePluginCannotReachTheFrontmatter(int $closingEnd, bool $warned): void
    {
        $head = "---\nname: big\ndescription: Fixture.\nnote: ";
        $padding = \str_repeat('x', $closingEnd - \strlen($head) - \strlen("\n---"));
        $this->writeSkill('big', $head . $padding . "\n---\r\n\nBody.\n");

        $run = SkillValidation::run(['big'], $this->tmp);

        Assert::count($run->withCode('frontmatter-over-4k'), $warned ? 1 : 0, $run->dump());
    }

    #[DataSet(['\\\\attacker\\share\\x.md'], 'UNC with backslashes')]
    #[DataSet(['%5C%5Cattacker%5Cshare%5Cx.md'], 'URL-encoded UNC')]
    #[DataSet(['%2Fetc%2Fpasswd'], 'URL-encoded absolute path')]
    public function neverResolvesALinkToAnAbsoluteOrNetworkPath(string $target): void
    {
        $this->writeSkill('remote', "---\nname: remote\ndescription: Fixture.\n---\n\n[x]($target)\n");

        $run = SkillValidation::run(['remote'], $this->tmp);

        Assert::same($run->withCode('link-missing'), [], $run->dump());
        Assert::same($run->withCode('link-outside-skill'), [], $run->dump());
    }

    public function readsTheDirectoryNameAsStoredOnDisk(): void
    {
        $this->writeSkill('my-skill', "---\nname: my-skill\ndescription: Fixture.\n---\n\nBody.\n");
        if (!\is_dir("$this->tmp/My-Skill")) {
            Assert::true(true, 'case-sensitive filesystem: the other spelling does not exist');

            return;
        }

        $run = SkillValidation::run(['My-Skill'], $this->tmp);

        Assert::same($run->withCode('name-dir-mismatch'), [], $run->dump());
    }

    public function printsOneLinePerFindingAndASummary(): void
    {
        $output = new BufferedOutput();

        $exit = (new LintRunner())->run(self::FIXTURES, ['name-uppercase'], false, $output);

        Assert::same($exit, FindingReport::EXIT_ERRORS);
        $lines = \explode("\n", \rtrim($output->fetch()));
        Assert::string($lines[0])->contains('ERROR name-uppercase/SKILL.md:2: skill name "Name-Uppercase" must be lowercase');
        Assert::same(\end($lines), \sprintf('%d error(s), 0 warning(s)', \count($lines) - 1));
    }

    public function escapesControlCharactersInTextOutput(): void
    {
        $this->writeSkill('escape', "---\nname: escape\ndescription: Fixture.\n---\n\n[x](missing\x1B[31m.md)\n");
        $output = new BufferedOutput();

        (new LintRunner())->run($this->tmp, ['escape'], false, $output);

        $text = $output->fetch();
        Assert::false(\str_contains($text, "\x1B"), $text);
        Assert::string($text)->contains('missing\x1B[31m.md');
    }

    #[DataSet(["a\x1B[31mb", 'a\x1B[31mb'], 'C0 escape')]
    #[DataSet(["a\u{9B}b", 'a\x9Bb'], 'C1 in valid UTF-8')]
    #[DataSet(["a\x9B2Jb\xC3\xA9", 'a\x9B2Jb\xC3\xA9'], 'lone C1 byte in invalid UTF-8')]
    #[DataSet(["café\tok", "café\tok"], 'printable text and tab kept')]
    public function escapesWhatATerminalWouldInterpret(string $raw, string $expected): void
    {
        Assert::same(FindingReport::escape($raw), $expected);
    }

    public function bundledSkillsAreValid(): void
    {
        $run = SkillValidation::run(['resources/skills'], \dirname(__DIR__, 2));

        Assert::same($run->exitCode, 0, $run->dump());
        Assert::same($run->atLevel('ERROR'), [], $run->dump());
    }

    private function writeSkill(string $dir, string $content): void
    {
        \mkdir("$this->tmp/$dir", 0o777, true);
        \file_put_contents("$this->tmp/$dir/SKILL.md", $content);
    }
}
