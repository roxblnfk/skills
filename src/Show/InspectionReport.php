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
final class InspectionReport
{
    /**
     * @param Path $target absolute destination directory used for sync-status checks
     * @param list<DonorInspection> $donors approved donor groups, in discovery order
     * @param list<SkippedDonor> $skipped donors that did not make it into the main listing
     * @param bool $discoveryActive whether the effective `--discovery` flag is on for this run
     * @param int $undeclaredCandidatesCount how many installed packages ship a `skills/` directory but
     *         do not declare `extra.skills`; drives the "rerun with --discovery" hint when discovery is off
     * @param list<AliasInspection> $aliases mirrors of `$target`, each with optional drift info.
     *         Empty list means "no aliases configured", which is the default and corresponds to
     *         the entire 1.x contract — the formatter hides the `Aliases:` header in that case.
     * @param bool $targetFromLayer whether the target comes from a config layer over the shared
     *         configuration, such as `skills.local.json`
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly Path $target,
        public readonly array $donors,
        public readonly array $skipped,
        public readonly bool $discoveryActive = false,
        public readonly int $undeclaredCandidatesCount = 0,
        public readonly array $aliases = [],
        public readonly bool $targetFromLayer = false,
    ) {}
}
