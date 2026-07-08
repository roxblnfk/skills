<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Source;

use Internal\Path;

/**
 * Source of {@see RemoteDonorRef}s for {@see SourceProvider}.
 *
 * Deliberately tiny: the provider has no business knowing whether
 * refs come from `skills.json`, a vendor's declared remote donors,
 * a future `skills:add`-managed lockfile, or somewhere else. Each
 * config surface becomes its own implementation.
 *
 * Implementations MUST be silent on inactive / missing input —
 * return an empty iterable rather than throw. The provider treats
 * an empty source as "no remote donors configured" and reports
 * {@see SourceProvider::isActive()} = `false`.
 *
 * @psalm-suppress MissingInterfaceImmutableAnnotation
 *         the interface is deliberately NOT immutable — implementations may read filesystem
 *         and config, mirroring the {@see \LLM\Skills\Discovery\Provider\DonorProvider} contract
 */
interface DonorRefSource
{
    /**
     * @return iterable<RemoteDonorRef|DirDonorRef>
     *
     * @psalm-suppress MissingAbstractPureAnnotation
     *         implementations talk to filesystem / config and are not pure
     */
    public function refs(Path $projectRoot): iterable;

    /**
     * Cheap "does this source have anything configured?" check.
     *
     * MUST NOT resolve refs — the answer drives
     * {@see SourceProvider::isActive()}, which is called once per sync
     * (and once per command for `show`). A naive "iterate refs() once"
     * implementation would trigger HTTP roundtrips on every command,
     * doubling network traffic when {@see SourceProvider::discover()}
     * subsequently iterates the same source.
     *
     * Implementations should answer by inspecting their config surface
     * directly (e.g. "does skills.json declare any `sources[]` entries"),
     * not by producing refs.
     *
     * @psalm-suppress MissingAbstractPureAnnotation
     */
    public function hasRefs(Path $projectRoot): bool;

    /**
     * Warnings accumulated during the most recent {@see self::refs()}
     * iteration. Typical contents: "unknown adapter id" for entries the
     * registry rejected, "no matching tag" for caret constraints with
     * no resolvable tag. Empty for sources that never fail mid-stream
     * (e.g. {@see NullDonorRefSource}).
     *
     * Implementations populate this during iteration; the provider
     * reads it AFTER consuming the iterable and merges the contents
     * into the same warnings channel that carries fetcher failures.
     *
     * @return list<string>
     *
     * @psalm-suppress MissingAbstractPureAnnotation
     */
    public function warnings(): array;
}
