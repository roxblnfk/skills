<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider;

use Internal\Path;
use LLM\Skills\Discovery\DonorDiscoveryResult;
use LLM\Skills\Discovery\MalformedDonor;

/**
 * Stacks several {@see DonorProvider}s behind a single interface so the
 * sync/show runners stay provider-agnostic.
 *
 * Children are queried in **declaration order**; on duplicate
 * `packageName` collisions the later child wins (the displaced earlier
 * entry is reported as a `-v` warning per spec §6.5). Wire the
 * composite with locals first and remote last: an explicit `remote[]`
 * entry then naturally overrides a transitive local discovery of the
 * same package name.
 *
 * `isActive()` is the OR of all children. `directDependencies()` is the
 * union (deduplicated, order-preserved). `discover()` concatenates
 * donors and warnings, then deduplicates donors as described above —
 * malformed and discoverable lists are concatenated verbatim because
 * their consumers do not care about cross-provider conflicts.
 *
 * @psalm-suppress MissingImmutableAnnotation
 *         the composite holds {@see DonorProvider} children which are deliberately not
 *         immutable; the composite itself is `final readonly` but inherits their impurity
 */
final readonly class CompositeDonorProvider implements DonorProvider
{
    /** @var list<DonorProvider> */
    private array $children;

    /**
     * @psalm-mutation-free
     */
    public function __construct(DonorProvider ...$children)
    {
        // Normalise to a plain list — variadic always yields a 0-indexed
        // array in PHP 8.1+, but pinning the shape locally keeps psalm's
        // list<DonorProvider> inference stable across consumers.
        $this->children = \array_values($children);
    }

    #[\Override]
    public function isActive(Path $projectRoot): bool
    {
        foreach ($this->children as $child) {
            if ($child->isActive($projectRoot)) {
                return true;
            }
        }
        return false;
    }

    #[\Override]
    public function discover(Path $projectRoot): DonorDiscoveryResult
    {
        /** @var array<non-empty-string, \LLM\Skills\Config\VendorConfig> $donorsByName */
        $donorsByName = [];
        /** @var list<non-empty-string> $shadowed */
        $shadowed = [];
        /** @var list<string> $warnings */
        $warnings = [];
        /** @var list<MalformedDonor> $malformed */
        $malformed = [];
        /** @var list<\LLM\Skills\Config\VendorConfig> $discoverable */
        $discoverable = [];

        foreach ($this->children as $child) {
            if (!$child->isActive($projectRoot)) {
                continue;
            }

            $result = $child->discover($projectRoot);

            foreach ($result->donors as $donor) {
                if (isset($donorsByName[$donor->packageName])) {
                    // Later child wins (remote over local — spec §6.5).
                    // The displaced earlier entry becomes a warning so
                    // `-v` users can see what got overridden, but the
                    // sync proceeds normally.
                    $shadowed[] = $donorsByName[$donor->packageName]->packageName;
                }
                $donorsByName[$donor->packageName] = $donor;
            }

            foreach ($result->warnings as $w) {
                $warnings[] = $w;
            }
            foreach ($result->malformed as $m) {
                $malformed[] = $m;
            }
            foreach ($result->discoverable as $d) {
                $discoverable[] = $d;
            }
        }

        foreach ($shadowed as $name) {
            $warnings[] = \sprintf(
                'donor "%s" was provided by multiple providers; the later (remote) one wins',
                $name,
            );
        }

        return new DonorDiscoveryResult(
            donors: \array_values($donorsByName),
            warnings: $warnings,
            malformed: $malformed,
            discoverable: $discoverable,
        );
    }

    #[\Override]
    public function directDependencies(Path $projectRoot): array
    {
        $seen = [];
        $out = [];
        foreach ($this->children as $child) {
            foreach ($child->directDependencies($projectRoot) as $name) {
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $out[] = $name;
            }
        }
        return $out;
    }
}
