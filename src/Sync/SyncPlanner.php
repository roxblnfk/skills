<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use Internal\Path;
use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\TrustedVendors;
use LLM\Skills\Config\VendorConfig;

/**
 * Pure planning step between Composer discovery and {@see SyncEngine}.
 *
 * Inputs: the already-mapped list of donor packages, plus the three config
 * objects ({@see ProjectConfig}, {@see SyncOptions}, builtin trust list) and
 * the project root path. The planner does not touch the filesystem or
 * Composer's API — that work is the command's responsibility.
 *
 * Outputs: a {@see SyncPlan} that partitions donors into "approved" (will be
 * synced) and "skipped — untrusted" (silently dropped, surfaced via the
 * trailing `[skip]` notice). The plan also resolves the absolute target
 * directory.
 *
 * Trust rules:
 *
 * - Naming a package as a positional argument is an implicit grant of trust:
 *   if the user types `composer skills:update acme/foo`, the planner treats
 *   `acme/foo` as approved regardless of the trust list. The trust list is
 *   the bouncer for *auto-discovered* donors, not for ones the user already
 *   asked for by name.
 * - Without positional filters, every donor must clear the effective trust
 *   list (built-in ∪ project ∪ `--trust`).
 */
final readonly class SyncPlanner
{
    /**
     * @param list<VendorConfig> $donors all donor packages successfully mapped from Composer
     */
    public function plan(
        array $donors,
        ProjectConfig $project,
        SyncOptions $options,
        TrustedVendors $builtin,
        Path $projectRoot,
    ): SyncPlan {
        [$filtered, $filteredOut] = $this->partitionByFilter($donors, $options);

        $approved = [];
        $skipped = [];

        if ($options->hasPackageFilters()) {
            // Positional naming is an implicit trust grant — every donor that
            // survived the filter goes straight to approved without consulting
            // the trust list.
            $approved = $filtered;
        } else {
            $trust = $this->effectiveTrust($project, $options, $builtin);
            foreach ($filtered as $donor) {
                if ($trust->trusts($donor->packageName)) {
                    $approved[] = $donor;
                    continue;
                }

                // Auto-discovered + untrusted — silently dropped from sync;
                // the command surfaces a one-line notice so the user knows
                // skills were ignored.
                $skipped[] = $donor->packageName;
            }
        }

        return new SyncPlan(
            approvedDonors: $approved,
            skippedUntrustedNames: $skipped,
            target: $this->resolveTarget($project, $options, $projectRoot),
            filteredOutDonors: $filteredOut,
        );
    }

    /**
     * Cross-platform absolute path detection: handles POSIX `/foo`, Windows
     * drive letters `C:\foo` and Windows UNC roots `\\server\share`.
     *
     * @psalm-pure
     */
    private static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }
        if (\strlen($path) >= 3 && \ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '/' || $path[2] === '\\')) {
            return true;
        }

        return false;
    }

    /**
     * @psalm-mutation-free
     */
    private function effectiveTrust(
        ProjectConfig $project,
        SyncOptions $options,
        TrustedVendors $builtin,
    ): TrustedVendors {
        $extras = new TrustedVendors($options->extraTrusted);

        return $project->trustedReplace
            ? $project->trusted->merge($extras)
            : $builtin->merge($project->trusted)->merge($extras);
    }

    /**
     * Split discovered donors into "kept" and "rejected by positional filter".
     *
     * Both halves are needed downstream: the kept half feeds trust resolution,
     * the rejected half is surfaced by `skills:show` under `Skipped:` so users
     * can see which donors a filter dropped without re-running without it.
     *
     * @param list<VendorConfig> $donors
     *
     * @return array{0: list<VendorConfig>, 1: list<VendorConfig>}
     *
     * @psalm-mutation-free
     */
    private function partitionByFilter(array $donors, SyncOptions $options): array
    {
        if (!$options->hasPackageFilters()) {
            return [$donors, []];
        }

        $kept = [];
        $rejected = [];
        foreach ($donors as $donor) {
            if ($options->matchesFilter($donor->packageName)) {
                $kept[] = $donor;
            } else {
                $rejected[] = $donor;
            }
        }

        return [$kept, $rejected];
    }

    private function resolveTarget(
        ProjectConfig $project,
        SyncOptions $options,
        Path $projectRoot,
    ): Path {
        /** @var non-empty-string $raw */
        $raw = $options->targetOverride ?? $project->target;

        return self::isAbsolute($raw)
            ? Path::create($raw)
            : $projectRoot->join($raw);
    }
}
