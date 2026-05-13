<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Exception;

/**
 * Raised when the consumer project's root `extra.skills` block does not match
 * the expected shape.
 *
 * The sync command does **not** catch this: a malformed root config is the
 * project owner's responsibility, so we surface a fatal error rather than
 * silently fall back to defaults.
 */
final class MalformedProjectConfig extends ConfigException
{
    /**
     * @param non-empty-string $reason
     *
     * @psalm-mutation-free
     */
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
