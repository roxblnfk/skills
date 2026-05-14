<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use Internal\Path;

/**
 * Probes a package install directory for an undeclared skills root.
 *
 * Used by {@see DonorDiscovery} for packages that do **not** declare
 * `extra.skills` in their `composer.json`. The probe is always run so the
 * caller can both (a) feed discovered donors into the pipeline when the
 * `--discovery` flag is on, and (b) print a hint when the flag is off but
 * undeclared candidates exist.
 *
 * v1 scope: a single well-known root, `skills/` directly under the package
 * install path. Future revisions may add a priority list of conventions
 * (`.agents/skills`, `.claude/skills`, ...).
 *
 * Junction safety: the resolved real path must stay inside the package
 * root. A symlink/junction that escapes the package boundary is silently
 * rejected — we never follow such pointers, even read-only.
 */
final readonly class AutoDiscoveryProbe
{
    /**
     * Single well-known skills root, relative to the package install path.
     *
     * @var non-empty-string
     */
    public const SOURCE_DIR = 'skills';

    /**
     * Look for a skills root inside the given package install path. Returns
     * the relative source directory (currently always {@see self::SOURCE_DIR})
     * when one exists and is contained within the package; otherwise `null`.
     *
     * @return non-empty-string|null
     */
    public function probe(Path $packageRoot): ?string
    {
        $candidate = (string) $packageRoot->join(self::SOURCE_DIR);
        if (!\is_dir($candidate)) {
            return null;
        }

        $resolvedRoot = \realpath((string) $packageRoot);
        $resolvedCandidate = \realpath($candidate);
        if ($resolvedRoot === false || $resolvedCandidate === false) {
            return null;
        }

        $rootPrefix = \rtrim($resolvedRoot, '/\\') . \DIRECTORY_SEPARATOR;
        $candidatePath = \rtrim($resolvedCandidate, '/\\') . \DIRECTORY_SEPARATOR;
        if (!\str_starts_with($candidatePath, $rootPrefix)) {
            return null;
        }

        return self::SOURCE_DIR;
    }
}
