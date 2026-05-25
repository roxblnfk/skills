<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo\Composer;

use Internal\Path;
use Symfony\Component\Process\Process;

/**
 * Counterpart of {@see ComposerRunner} for the standalone `bin/skills`
 * binary. Used by acceptance tests that exercise the script's standalone
 * mode — running outside any Composer project (no `composer.json` at the
 * cwd) so the Composer bootstrap must NOT be required for success.
 *
 * The wrapper appends `--no-interaction --no-ansi` for stable output
 * capture, mirroring {@see ComposerRunner}.
 */
final class BinSkillsRunner
{
    /**
     * Absolute path to the project's `bin/skills` script. Resolved once
     * from this file's location so callers do not need to figure it out.
     */
    public const BIN_PATH = __DIR__ . '/../../../bin/skills';

    /**
     * @param non-empty-string $command Subcommand and arguments, e.g. `update --discovery`.
     * @param int<1, max> $timeout Hard timeout in seconds.
     */
    public static function run(
        Path $cwd,
        string $command,
        int $timeout = 60,
    ): Process {
        \fwrite(\STDERR, "[acceptance] bin/skills {$command} in {$cwd} …\n");

        $process = Process::fromShellCommandline(
            \sprintf('php %s %s --no-interaction --no-ansi', \escapeshellarg(self::BIN_PATH), $command),
            cwd: (string) $cwd,
            env: ['SHELL_VERBOSITY' => '0'],
        );
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }
}
