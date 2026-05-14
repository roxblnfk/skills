<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

use Internal\Path;

/**
 * The full data model rendered by `skills:show`.
 *
 * Built by {@see InspectionBuilder} from the same discovery and trust
 * pipeline that {@see \LLM\Skills\Sync\SyncRunner} uses, then handed to
 * {@see ReportFormatter} for text rendering.
 *
 * The report carries *what was observed*, not how to display it: the
 * formatter is free to group by vendor, sort by name, drop annotations,
 * or render JSON without touching the builder.
 *
 * @psalm-immutable
 */
final readonly class InspectionReport
{
    /**
     * @param Path $target absolute destination directory used for sync-status checks
     * @param list<DonorInspection> $donors approved donor groups, in discovery order
     * @param list<SkippedDonor> $skipped donors that did not make it into the main listing
     * @param bool $discoveryActive whether the effective `--discovery` flag is on for this run
     * @param int $undeclaredCandidatesCount how many installed packages ship a `skills/` directory but
     *         do not declare `extra.skills`; drives the "rerun with --discovery" hint when discovery is off
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public Path $target,
        public array $donors,
        public array $skipped,
        public bool $discoveryActive = false,
        public int $undeclaredCandidatesCount = 0,
    ) {}
}
