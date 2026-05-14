<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\VendorConfig;

/**
 * Decides which auto-discovered donors are merged into a sync/show run and
 * which are merely candidates to mention in the trailing hint.
 *
 * Two opt-in paths for a discovered donor:
 *
 * - **Global**: `--discovery` is on (CLI or {@see \LLM\Skills\Config\ProjectConfig::$discovery}).
 *   Every discovered donor is included.
 * - **Per-package**: the user named the donor (or a vendor wildcard covering
 *   it) as a positional argument. Naming a package is an implicit "I want
 *   this", which here means both "trust it" and "auto-discover skills inside
 *   it if it does not declare any".
 *
 * Everything not included falls into `$excluded`, which feeds the
 * `[hint] N package(s) ship undeclared skills` notice in `update` and the
 * `not-declared` rows in the `show` Skipped section.
 *
 * @psalm-immutable
 */
final readonly class DiscoveryResolver
{
    /**
     * @param list<VendorConfig> $discoverable
     *
     * @psalm-mutation-free
     */
    public function resolve(
        array $discoverable,
        bool $discoveryActive,
        SyncOptions $options,
    ): DiscoveryResolution {
        if ($discoveryActive) {
            return new DiscoveryResolution(included: $discoverable, excluded: []);
        }

        if (!$options->hasPackageFilters()) {
            return new DiscoveryResolution(included: [], excluded: $discoverable);
        }

        $included = [];
        $excluded = [];
        foreach ($discoverable as $donor) {
            if ($options->matchesFilter($donor->packageName)) {
                $included[] = $donor;
            } else {
                $excluded[] = $donor;
            }
        }

        return new DiscoveryResolution(included: $included, excluded: $excluded);
    }
}
