<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

/**
 * Something readable but suspicious met while parsing `SKILL.md`; reported as a warning.
 *
 * @internal
 *
 * @psalm-immutable
 */
final readonly class ParseNote
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        public int $line,
        public string $code,
        public string $message,
    ) {}
}
