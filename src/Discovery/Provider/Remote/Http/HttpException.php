<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote\Http;

/**
 * Raised when an HTTP call fails at the transport level — DNS,
 * connection refused, TLS error, timeout, etc.
 *
 * Non-2xx responses are NOT exceptions: they come back as a
 * {@see HttpResponse} with the relevant status, because adapters often
 * need to inspect 404s (no such tag) and 403s (rate limit) and act on
 * them rather than crash.
 */
final class HttpException extends \RuntimeException
{
    /**
     * @param non-empty-string $url URL the call was made against, surfaced in diagnostics
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly string $url,
        string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('HTTP request to %s failed: %s', $url, $reason),
            previous: $previous,
        );
    }
}
