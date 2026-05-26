<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote;

/**
 * Thrown by {@see RemoteFetcher::fetch()} when a ref cannot be
 * resolved into a local extracted archive.
 *
 * Covers every failure mode the fetcher might encounter — unknown
 * host, transport error, missing ref, archive corruption, disk
 * write failure — bundled into one exception type so the provider
 * can downgrade them all to a single per-ref warning. Carries the
 * {@see RemoteDonorRef} so the warning can name what failed.
 */
final class RemoteFetchException extends \RuntimeException
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly RemoteDonorRef $ref,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
