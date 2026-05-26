<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote;

use Internal\Path;

/**
 * Source of {@see RemoteDonorRef}s for {@see RemoteProvider}.
 *
 * Deliberately tiny: the provider has no business knowing whether
 * refs come from `skills.json`, a vendor's declared remote donors,
 * a future `skills:add`-managed lockfile, or somewhere else. Each
 * config surface becomes its own implementation.
 *
 * Implementations MUST be silent on inactive / missing input —
 * return an empty iterable rather than throw. The provider treats
 * an empty source as "no remote donors configured" and reports
 * {@see RemoteProvider::isActive()} = `false`.
 *
 * @psalm-suppress MissingInterfaceImmutableAnnotation
 *         the interface is deliberately NOT immutable — implementations may read filesystem
 *         and config, mirroring the {@see \LLM\Skills\Discovery\Provider\DonorProvider} contract
 */
interface RemoteDonorSource
{
    /**
     * @return iterable<RemoteDonorRef>
     *
     * @psalm-suppress MissingAbstractPureAnnotation
     *         implementations talk to filesystem / config and are not pure
     */
    public function refs(Path $projectRoot): iterable;
}
