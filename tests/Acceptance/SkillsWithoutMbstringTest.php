<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use LLM\Skills\Tests\Testo\Composer\BinSkillsRunner;
use LLM\Skills\Tests\Testo\Filesystem;
use LLM\Skills\Tests\Testo\PhpWithoutMbstring;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * A sync on PHP without the mbstring and intl extensions, where only the
 * Symfony polyfills that Composer ships stand in for them.
 */
#[Test]
final class SkillsWithoutMbstringTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-no-mbstring-' . \bin2hex(\random_bytes(6));
        foreach (['greeting', 'Über'] as $skill) {
            \mkdir("{$this->tmp}/local-skills/{$skill}", 0o777, true);
            \file_put_contents(
                "{$this->tmp}/local-skills/{$skill}/SKILL.md",
                "---\nname: {$skill}\ndescription: A skill served from a local directory source.\n---\n",
            );
        }
        \file_put_contents(
            $this->tmp . '/skills.json',
            \json_encode(['sources' => [['from' => 'dir', 'path' => './local-skills']]]),
        );
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function syncInstallsAsciiSkillsAndSkipsTheRest(): void
    {
        $php = PhpWithoutMbstring::command() ?? throw new SkipTest('mbstring or intl is compiled into PHP');
        $process = new Process(
            [...$php, BinSkillsRunner::BIN_PATH, 'update', '--no-interaction', '--no-ansi'],
            cwd: $this->tmp,
            env: ['SHELL_VERBOSITY' => '0'],
        );
        $process->setTimeout(60);
        $process->run();
        $output = $process->getOutput() . $process->getErrorOutput();

        Assert::same($process->getExitCode(), 0, $output);
        Assert::true(\is_file($this->tmp . '/.agents/skills/greeting/SKILL.md'), $output);
        Assert::false(\is_dir($this->tmp . '/.agents/skills/Über'), $output);
        Assert::true(\str_contains($output, 'is not ASCII'), $output);
    }
}
