<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote;

use Internal\Path;

/**
 * Resolves a {@see RemoteDonorRef} into a local directory whose
 * contents are the donor's extracted archive — i.e. a path that
 * looks like a Composer package install dir: a `composer.json` at
 * the root, source directories beside it.
 *
 * Implementations own where the extracted content lives (cache dir,
 * tmpdir, vendor-side scratch space) and when to re-fetch. The
 * provider treats the returned `Path` the same way it treats a
 * Composer-installed package root: feeds it to
 * {@see \LLM\Skills\Config\Mapper\VendorConfigMapper}.
 *
 * Errors of any kind — unknown host, transport failure, missing
 * ref, corrupted archive, disk write failure — surface as
 * {@see RemoteFetchException}, which the provider downgrades to a
 * per-ref warning. One bad ref must never block the rest.
 *
 * @psalm-suppress MissingInterfaceImmutableAnnotation
 *         the interface is deliberately NOT immutable — implementations talk to network and
 *         filesystem, mirroring the {@see \LLM\Skills\Discovery\Provider\DonorProvider} contract
 */
interface RemoteFetcher
{
    /**
     * @throws RemoteFetchException when the ref cannot be fetched or extracted
     *
     * @psalm-suppress MissingAbstractPureAnnotation
     *         implementations talk to network / filesystem and are not pure
     */
    public function fetch(RemoteDonorRef $ref): Path;
}
