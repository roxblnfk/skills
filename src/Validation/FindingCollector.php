<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

/**
 * Accumulates findings in report order, dropping exact repeats: the same file can be
 * reached through several of the paths a run is given.
 *
 * @internal
 */
final class FindingCollector
{
    /** @var list<Finding> */
    private array $findings = [];

    /** @var array<string, true> */
    private array $seen = [];

    /**
     * @psalm-external-mutation-free
     */
    public function error(string $file, ?int $line, string $code, string $message): void
    {
        $this->add(new Finding(Severity::Error, $file, $line, $code, $message));
    }

    /**
     * @psalm-external-mutation-free
     */
    public function warn(string $file, ?int $line, string $code, string $message): void
    {
        $this->add(new Finding(Severity::Warning, $file, $line, $code, $message));
    }

    /**
     * @psalm-external-mutation-free
     */
    public function info(string $file, ?int $line, string $code, string $message): void
    {
        $this->add(new Finding(Severity::Info, $file, $line, $code, $message));
    }

    /**
     * @psalm-external-mutation-free
     */
    public function add(Finding $finding): void
    {
        $key = \implode('|', [
            $finding->severity->value,
            $finding->file,
            (string) $finding->line,
            $finding->code,
            $finding->message,
        ]);
        if (isset($this->seen[$key])) {
            return;
        }
        $this->seen[$key] = true;
        $this->findings[] = $finding;
    }

    /**
     * Adds another collector's findings with each error turned into a warning: problems the
     * project cannot fix itself, such as in a donor's skill, never fail the run.
     *
     * @psalm-external-mutation-free
     */
    public function addAsWarnings(self $other): void
    {
        foreach ($other->findings as $f) {
            $this->add($f->severity === Severity::Error
                ? new Finding(Severity::Warning, $f->file, $f->line, $f->code, $f->message)
                : $f);
        }
    }

    /**
     * @return list<Finding>
     *
     * @psalm-mutation-free
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * @return int<0, max>
     *
     * @psalm-mutation-free
     */
    public function count(Severity $severity): int
    {
        return \count(\array_filter(
            $this->findings,
            static fn(Finding $f): bool => $f->severity === $severity,
        ));
    }
}
