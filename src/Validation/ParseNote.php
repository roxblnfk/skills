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
final class ParseNote
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly int $line,
        public readonly string $code,
        public readonly string $message,
    ) {}
}
