<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo;

use Symfony\Component\Process\Process;

/**
 * One run of a helper script bundled with the `llm-skills` skill
 * (`resources/skills/llm-skills/scripts/*.php`), executed as a PHP subprocess.
 *
 * Findings are read from the `--json` output, which the run requests unless
 * told otherwise.
 */
final class SkillScript
{
    public const CHECK_PROJECT = 'check-project.php';
    public const SCAN = 'scan-skill.php';

    public const SKILL_DIR = __DIR__ . '/../../resources/skills/llm-skills';

    /** @var list<array{level: string, file: string, line: int|null, message: string, code: string}>|null */
    private ?array $findings = null;

    private function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {}

    /**
     * @param list<string> $args script arguments; "--json" is added unless $json is false
     * @param string $cwd working directory, which reported paths are relative to
     */
    public static function run(string $script, array $args, string $cwd, bool $json = true): self
    {
        $command = [\PHP_BINARY, self::SKILL_DIR . '/scripts/' . $script, ...($json ? ['--json'] : []), ...$args];
        $process = new Process($command, $cwd, ['XDEBUG_MODE' => 'off'], timeout: 60);
        $process->run();

        return new self((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
    }

    /**
     * Decoded `--json` output.
     *
     * @return list<array{level: string, file: string, line: int|null, message: string, code: string}>
     */
    public function findings(): array
    {
        if ($this->findings === null) {
            $decoded = \json_decode($this->stdout, true);
            if (!\is_array($decoded)) {
                throw new \RuntimeException(\sprintf(
                    "Script output is not a JSON array (exit %d).\nstdout: %s\nstderr: %s",
                    $this->exitCode,
                    $this->stdout,
                    $this->stderr,
                ));
            }
            $this->findings = $decoded;
        }

        return $this->findings;
    }

    /**
     * Findings with the given code, optionally narrowed to one level and one file.
     *
     * @return list<array{level: string, file: string, line: int|null, message: string, code: string}>
     */
    public function withCode(string $code, ?string $level = null, ?string $file = null): array
    {
        return \array_values(\array_filter(
            $this->findings(),
            static fn(array $f): bool => $f['code'] === $code
                && ($level === null || $f['level'] === $level)
                && ($file === null || $f['file'] === $file),
        ));
    }

    /**
     * Findings of one level.
     *
     * @return list<array{level: string, file: string, line: int|null, message: string, code: string}>
     */
    public function atLevel(string $level): array
    {
        return \array_values(\array_filter($this->findings(), static fn(array $f): bool => $f['level'] === $level));
    }

    /**
     * Compact rendering of every finding, for assertion failure messages.
     */
    public function dump(): string
    {
        $lines = \array_map(
            static fn(array $f): string => \sprintf('%s %s:%s %s', $f['level'], $f['file'], $f['line'] ?? '-', $f['code']),
            $this->findings(),
        );

        return \sprintf("exit %d\n%s", $this->exitCode, \implode("\n", $lines));
    }
}
