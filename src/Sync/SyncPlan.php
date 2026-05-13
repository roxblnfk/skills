<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use Internal\Path;
use LLM\Skills\Config\VendorConfig;

/**
 * Result of {@see SyncPlanner::plan()} — a partitioning of donor packages by
 * trust outcome plus the resolved target path.
 *
 * The plan is a pure description: it makes no IO and contains no decisions
 * that require user interaction. The sync command consumes it, prompts when
 * needed, and feeds the final approved list to {@see SyncEngine}.
 *
 * @psalm-immutable
 */
final readonly class SyncPlan
{
    /**
     * @param list<VendorConfig>       $approvedDonors        donors that pass trust and filter — copy them.
     * @param list<VendorConfig>       $untrustedNamedDonors  donors explicitly named by the CLI filter
     *                                                        but **not** in any trust list. Interactive
     *                                                        mode prompts; non-interactive logs a warning
     *                                                        and proceeds with sync.
     * @param list<non-empty-string>   $skippedUntrustedNames donors discovered by auto-discovery (no
     *                                                        positional arg) that are not trusted. Skipped
     *                                                        silently except for a notice.
     * @param Path                     $target                absolute destination directory
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public array $approvedDonors,
        public array $untrustedNamedDonors,
        public array $skippedUntrustedNames,
        public Path $target,
    ) {}
}
