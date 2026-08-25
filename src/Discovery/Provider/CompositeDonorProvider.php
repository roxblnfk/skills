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
 * Children are queried in **declaration order**; when two children
 * provide the same `packageName` the later child wins and displaces
 * every row the earlier one contributed (reported as a `-v` warning).
 * Multiple rows for one package from the SAME child are siblings — a
 * multi-directory `source` declaration — and all survive. Wire the
 * composite with locals first and remote last so an explicit
 * `sources[]` entry naturally overrides a transitive local discovery
 * of the same package name.
 *
 * `isActive()` is the OR of all children. `directDependencies()` is the
 * union (deduplicated, order-preserved). `discover()` concatenates
 * donors and warnings, then deduplicates donors as described above —
 * malformed, discoverable, and failure lists are concatenated verbatim
 * because their consumers do not care about cross-provider conflicts.
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
        /** @var array<non-empty-string, non-empty-list<\LLM\Skills\Config\VendorConfig>> $donorsByName */
        $donorsByName = [];
        /** @var array<non-empty-string, int> $ownerIndex which child currently owns the package */
        $ownerIndex = [];
        /** @var list<string> $warnings */
        $warnings = [];
        /** @var list<MalformedDonor> $malformed */
        $malformed = [];
        /** @var list<\LLM\Skills\Config\VendorConfig> $discoverable */
        $discoverable = [];
        /** @var list<\LLM\Skills\Discovery\SourceFailure> $failures */
        $failures = [];

        foreach ($this->children as $index => $child) {
            if (!$child->isActive($projectRoot)) {
                continue;
            }

            $result = $child->discover($projectRoot);

            foreach ($result->donors as $donor) {
                $name = $donor->packageName;
                if (isset($donorsByName[$name]) && $ownerIndex[$name] !== $index) {
                    // Later child wins and displaces every row the
                    // earlier child contributed for this package. The
                    // takeover becomes a warning so `-v` users can see
                    // what got overridden; the sync proceeds normally.
                    // The composite is generic — the winner is
                    // "whichever provider declared the package last"
                    // (typically remote, since callers wire it last to
                    // honour the explicit-over-transitive rule), not
                    // necessarily remote in every wiring. Rows arriving
                    // from the SAME child are siblings, not conflicts: a
                    // multi-directory `source` declaration is one donor
                    // spread over several rows.
                    $loser = $donorsByName[$name][0];
                    $warnings[] = \sprintf(
                        'donor "%s" was provided by multiple providers — '
                        . 'later "%s" overrides earlier "%s"',
                        $name,
                        $donor->provenance,
                        $loser->provenance,
                    );
                    unset($donorsByName[$name]);
                }
                $ownerIndex[$name] = $index;
                $donorsByName[$name][] = $donor;
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
            foreach ($result->failures as $f) {
                $failures[] = $f;
            }
        }

        return new DonorDiscoveryResult(
            donors: \array_merge([], ...\array_values($donorsByName)),
            warnings: $warnings,
            malformed: $malformed,
            discoverable: $discoverable,
            failures: $failures,
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
