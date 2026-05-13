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
 * Outputs: a {@see SyncPlan} that partitions donors into "approved",
 * "untrusted but named on the CLI" (needs an interactive nod), and
 * "untrusted, skipped silently". The plan also resolves the absolute target
 * directory.
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
        $trust = $this->effectiveTrust($project, $options, $builtin);
        $filtered = $this->applyFilter($donors, $options);

        $approved = [];
        $untrustedNamed = [];
        $skipped = [];

        foreach ($filtered as $donor) {
            if ($trust->trusts($donor->packageName)) {
                $approved[] = $donor;
                continue;
            }

            if ($options->hasPackageFilters()) {
                // Explicitly named on the CLI — caller will prompt (interactive)
                // or warn-and-proceed (non-interactive).
                $untrustedNamed[] = $donor;
                continue;
            }

            // Auto-discovered + untrusted — silently dropped from sync; the
            // command surfaces a one-line notice so the user knows skills
            // were ignored.
            $skipped[] = $donor->packageName;
        }

        return new SyncPlan(
            approvedDonors: $approved,
            untrustedNamedDonors: $untrustedNamed,
            skippedUntrustedNames: $skipped,
            target: $this->resolveTarget($project, $options, $projectRoot),
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
     * @param list<VendorConfig> $donors
     *
     * @return list<VendorConfig>
     *
     * @psalm-mutation-free
     */
    private function applyFilter(array $donors, SyncOptions $options): array
    {
        if (!$options->hasPackageFilters()) {
            return $donors;
        }

        return \array_values(\array_filter(
            $donors,
            static fn(VendorConfig $d): bool => $options->matchesFilter($d->packageName),
        ));
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
