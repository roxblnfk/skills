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
     * @param list<VendorConfig> $approvedDonors donors that pass trust (or were explicitly named on
     *         the CLI, which short-circuits the trust check) — copy them
     * @param list<non-empty-string> $skippedUntrustedNames donors discovered automatically (no positional
     *         arg) that are not in any trust list; skipped silently except for the trailing `[skip]` notice
     * @param Path $target absolute destination directory
     * @param list<Path> $aliases absolute alias paths; each is created as a junction (Windows) or
     *         symlink (POSIX) pointing at `$target` after the copy phase completes. Empty list means
     *         "no aliases for this run".
     * @param list<VendorConfig> $filteredOutDonors donors that were discovered but excluded by a positional
     *         `<package>` pattern. The sync command ignores this field — it exists for `show`, which lists
     *         these under `Skipped:` with a `filtered-out` reason.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public array $approvedDonors,
        public array $skippedUntrustedNames,
        public Path $target,
        public array $aliases = [],
        public array $filteredOutDonors = [],
    ) {}
}
