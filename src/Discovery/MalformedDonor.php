<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

/**
 * A donor package whose `extra.skills` block was rejected by
 * {@see \LLM\Skills\Config\Mapper\VendorConfigMapper}.
 *
 * Carries the same information that goes into a human-readable warning,
 * but in structured form so the `show` command can list it under
 * `Skipped:` with a `malformed` reason code. Plain string warnings still
 * exist alongside this (for IO emission); `MalformedDonor` is the typed
 * sibling that downstream consumers can pattern-match on.
 *
 * Discovery-time failures with **no** package context (e.g. Composer
 * could not resolve an install path) stay in the warnings stream — there
 * is no package name to attach them to.
 *
 * @psalm-immutable
 */
final readonly class MalformedDonor
{
    /**
     * @param non-empty-string $packageName Composer name of the offending donor
     * @param non-empty-string $reason mapper's explanation (already includes the offending key)
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $packageName,
        public string $reason,
    ) {}
}
