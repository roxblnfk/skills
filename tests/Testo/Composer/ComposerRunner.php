<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo\Composer;

use Internal\Path;
use Symfony\Component\Process\Process;

/**
 * Shared helper for running Composer CLI commands in the sandbox project from
 * inside testo interceptors.
 *
 * The wrapper always appends `--no-interaction --no-ansi` so it works under
 * subprocess capture. Non-zero exit codes raise
 * {@see \Symfony\Component\Process\Exception\ProcessFailedException}.
 */
final class ComposerRunner
{
    /**
     * @param non-empty-string $command Subcommand and its arguments, e.g. `install --prefer-dist`
     *        or `skills:sync`. `--no-interaction --no-ansi` are appended automatically.
     * @param int<1, max> $timeout Hard timeout in seconds.
     * @param bool $mustSucceed When `true` (default), a non-zero exit raises
     *        {@see \Symfony\Component\Process\Exception\ProcessFailedException}.
     *        Pass `false` when the caller wants to inspect the {@see Process}'s
     *        exit code or output regardless of success — typical for acceptance
     *        tests that assert on the result of the command itself.
     */
    public static function run(
        Path $projectRoot,
        string $command,
        int $timeout = 180,
        bool $mustSucceed = true,
    ): Process {
        \fwrite(\STDERR, "[acceptance] composer {$command} in {$projectRoot} …\n");

        $process = Process::fromShellCommandline(
            "composer {$command} --no-interaction --no-ansi",
            cwd: (string) $projectRoot,
        );
        $process->setTimeout($timeout);

        $mustSucceed
            ? $process->mustRun()
            : $process->run();

        return $process;
    }
}
