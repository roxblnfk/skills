<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

/**
 * One row in the `Skipped:` section of `skills:show`.
 *
 * `detail` is free-form context relevant to the reason. For
 * {@see SkipReason::Malformed} it carries the mapper's explanation. For
 * trust-related reasons it's typically `null` (the package name + the
 * reason code already say enough).
 *
 * @psalm-immutable
 */
final readonly class SkippedDonor
{
    /**
     * @param non-empty-string $packageName Composer name
     * @param SkipReason $reason machine-readable reason code
     * @param string|null $detail optional human-readable elaboration
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $packageName,
        public SkipReason $reason,
        public ?string $detail = null,
    ) {}
}
