<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Exception;

/**
 * Raised by {@see \LLM\Skills\Config\Mapper\SourceEntryMapper} when a
 * `sources[]` entry does not match the expected shape.
 *
 * This exception is an internal signal, not a policy decision: the entry
 * mapper is shared between the project config (where a broken entry is
 * fatal) and vendor packages (where it only skips the offending donor).
 * Each caller catches it and rethrows as {@see MalformedProjectConfig}
 * or {@see MalformedVendorConfig} respectively.
 */
final class MalformedSourceEntry extends ConfigException
{
    /**
     * @param non-empty-string $reason human-readable cause, already carrying the
     *        field path the caller passed to the mapper
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct($reason);
    }
}
