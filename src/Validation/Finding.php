<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

/**
 * One problem the skill validator found.
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class Finding
{
    /**
     * @param string $file path as shown to the user: relative to the working directory
     *        when inside it, else absolute; forward slashes
     * @param int|null $line 1-based line in `$file`, `null` when the finding is about the whole file
     * @param string $code stable kebab-case identifier of the check
     * @param string $message what is wrong, followed by how to fix it
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public Severity $severity,
        public string $file,
        public ?int $line,
        public string $code,
        public string $message,
    ) {}

    /**
     * @return array{level: string, file: string, line: int|null, message: string, code: string}
     *
     * @psalm-mutation-free
     */
    public function toArray(): array
    {
        return [
            'level' => $this->severity->value,
            'file' => $this->file,
            'line' => $this->line,
            'message' => $this->message,
            'code' => $this->code,
        ];
    }
}
