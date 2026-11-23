<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo;

use Internal\Path;
use LLM\Skills\Validation\ValidateProjectRunner;
use LLM\Skills\Validation\LintRunner;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * One in-process run of `lint` or `validate`, with findings read back from its
 * JSON output.
 */
final class SkillValidation
{
    /** @var list<array{level: string, file: string, line: int|null, message: string, code: string}> */
    private readonly array $findings;

    /**
     * @param list<array{level: string, file: string, line: int|null, message: string, code: string}> $findings
     */
    private function __construct(
        public readonly int $exitCode,
        array $findings,
    ) {
        $this->findings = $findings;
    }

    /**
     * @param list<string> $paths command arguments
     * @param string $cwd working directory, which reported paths are relative to
     */
    public static function run(array $paths, string $cwd): self
    {
        $output = new BufferedOutput();

        return self::fromJson((new LintRunner())->run($cwd, $paths, true, $output), $output->fetch());
    }

    /**
     * One in-process run of `validate` through {@see ValidateProjectRunner}, without Composer:
     * donors come from `dir` sources only.
     *
     * @param string $root project root, which reported paths are relative to
     */
    public static function runProject(string $root, bool $fetch = false): self
    {
        $output = new BufferedOutput();
        $exitCode = (new ValidateProjectRunner())->run(Path::create($root), null, $fetch, true, $output);

        return self::fromJson($exitCode, $output->fetch());
    }

    private static function fromJson(int $exitCode, string $json): self
    {
        $decoded = \json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        /** @var list<array{level: string, file: string, line: int|null, message: string, code: string}> $decoded */
        return new self($exitCode, $decoded);
    }

    /**
     * @return list<array{level: string, file: string, line: int|null, message: string, code: string}>
     */
    public function findings(): array
    {
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
            $this->findings,
            static fn(array $f): bool => $f['code'] === $code
                && ($level === null || $f['level'] === $level)
                && ($file === null || $f['file'] === $file),
        ));
    }

    /**
     * @return list<array{level: string, file: string, line: int|null, message: string, code: string}>
     */
    public function atLevel(string $level): array
    {
        return \array_values(\array_filter($this->findings, static fn(array $f): bool => $f['level'] === $level));
    }

    /**
     * Compact rendering of every finding, for assertion failure messages.
     */
    public function dump(): string
    {
        $lines = \array_map(
            static fn(array $f): string => \sprintf('%s %s:%s %s', $f['level'], $f['file'], $f['line'] ?? '-', $f['code']),
            $this->findings,
        );

        return \sprintf("exit %d\n%s", $this->exitCode, \implode("\n", $lines));
    }
}
