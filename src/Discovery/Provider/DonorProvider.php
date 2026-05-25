<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider;

use Internal\Path;
use LLM\Skills\Discovery\DonorDiscoveryResult;

/**
 * Source of donor packages for the sync pipeline.
 *
 * A provider knows how to enumerate donor packages from a specific
 * ecosystem (Composer/Packagist, GitHub, npm, a flat `skills.sh`
 * registry, …). The runner is agnostic — it gets a `DonorProvider`
 * and asks it for donors, regardless of where they came from.
 *
 * Today only {@see ComposerProvider} exists. The interface exists so
 * adding a `GithubProvider`, `NodeModulesProvider`, etc. is purely
 * additive: implement this interface, register the new provider in
 * the entrypoint, done.
 *
 * Implementations MUST be:
 *
 * - **Silent when inactive.** A provider that cannot contribute to
 *   the current project ({@see ComposerProvider} in a directory
 *   without `composer.json`, a hypothetical `NodeModulesProvider`
 *   when no `node_modules/` exists, etc.) returns an empty
 *   {@see DonorDiscoveryResult} from {@see self::discover()} and
 *   `false` from {@see self::isActive()}. They do NOT throw.
 * - **Idempotent.** Repeated calls produce the same output for
 *   identical inputs. The runner may call {@see self::discover()}
 *   more than once for a single run.
 *
 * Implementations read external state (filesystem, Composer's
 * repository manager, future network clients) so the interface is
 * intentionally NOT annotated `@psalm-immutable` — that would force
 * every implementation to be pure, ruling out Composer (whose
 * methods are not pure) as a dependency.
 *
 * @psalm-suppress MissingInterfaceImmutableAnnotation
 *         the interface is deliberately NOT immutable — implementations may carry impure
 *         dependencies like Composer
 */
interface DonorProvider
{
    /**
     * Whether this provider has anything to contribute in the
     * current project. The runner uses the aggregate of all providers'
     * `isActive()` to decide whether to emit the
     * `[no donors available]` notice (true when every provider
     * reports inactive — typically "no donor ecosystem detected").
     *
     * @psalm-suppress MissingAbstractPureAnnotation
     *         not all implementations are pure (the Composer one reads from a mutable repo)
     */
    public function isActive(Path $projectRoot): bool;

    /**
     * Walk the provider's source of truth and return every donor
     * package it can find at `$projectRoot`. Returns an empty result
     * (not `null`) when the provider is inactive or has nothing to
     * offer.
     *
     * @psalm-suppress MissingAbstractPureAnnotation
     *         implementations talk to Composer / network / filesystem and are not pure
     */
    public function discover(Path $projectRoot): DonorDiscoveryResult;

    /**
     * Names of packages this provider considers "direct dependencies"
     * of the consumer project — used for the implicit-trust rule
     * (a package declared in the consumer's manifest is trusted
     * without needing an explicit pattern).
     *
     * Providers without a notion of "direct dep" (e.g. a hypothetical
     * GitHub provider reading a flat list of repo URLs) return an
     * empty list. Composer is the only first-class case today.
     *
     * @return list<non-empty-string>
     *
     * @psalm-suppress MissingAbstractPureAnnotation
     *         the Composer implementation reads from the (mutable) root package metadata
     */
    public function directDependencies(Path $projectRoot): array;
}
