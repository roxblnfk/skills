<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote\Http;

/**
 * Result of a {@see HttpClient::get()} call.
 *
 * Minimal shape — adapters only need the status code, the response
 * body, and the headers map. Streaming is out of scope (config and
 * zipball payloads fit comfortably in memory).
 *
 * Header names are lowercased on construction so adapters can do
 * case-insensitive lookups without re-normalising.
 *
 * @psalm-immutable
 */
final readonly class HttpResponse
{
    /**
     * @param int $statusCode HTTP status code, e.g. 200 / 404 / 503
     * @param string $body raw response body
     * @param array<non-empty-string, string> $headers header map, keys lowercased
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $headers = [],
    ) {}

    /**
     * @psalm-mutation-free
     */
    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Lower-cased header lookup. Returns `null` when the header is
     * absent — `''` would be ambiguous with a present-but-empty header.
     *
     * @param non-empty-string $name
     *
     * @psalm-mutation-free
     */
    public function header(string $name): ?string
    {
        return $this->headers[\strtolower($name)] ?? null;
    }
}
