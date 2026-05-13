<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Exception;

/**
 * Raised when a donor package's `extra.skills` block exists but does not
 * match the expected shape (wrong types, missing `source`, etc.).
 *
 * The sync command catches this, emits a `-v` warning, and **continues** —
 * one broken vendor never blocks others.
 */
final class MalformedVendorConfig extends ConfigException
{
    /**
     * @param non-empty-string $packageName
     * @param non-empty-string $reason     human-readable cause for the `-v` warning
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly string $packageName,
        string $reason,
    ) {
        parent::__construct(\sprintf('Package "%s": %s', $packageName, $reason));
    }
}
