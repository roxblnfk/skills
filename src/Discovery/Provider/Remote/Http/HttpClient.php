<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote\Http;

/**
 * Tiny HTTP client abstraction used by remote source adapters.
 *
 * The interface is deliberately narrow — the adapters only need to
 * issue authenticated GET requests against well-known API endpoints
 * and against archive download URLs. Streaming, POST/PUT, and cookie
 * handling are out of scope.
 *
 * Real implementations wrap Composer's `HttpDownloader` (so the
 * adapter inherits `auth.json` / `COMPOSER_AUTH` credential
 * resolution for free); unit tests use an in-memory stub that returns
 * canned {@see HttpResponse} values.
 *
 * @psalm-suppress MissingInterfaceImmutableAnnotation
 *         implementations talk to the network — deliberately not pure
 */
interface HttpClient
{
    /**
     * Perform a GET request.
     *
     * Non-2xx responses are NOT exceptions — the caller often needs
     * to react to a 404 (no such tag) or 403 (rate limit) differently
     * from a network failure. Throw {@see HttpException} only for
     * transport-level errors (DNS, connection refused, timeout, …).
     *
     * @param non-empty-string $url
     * @param array<non-empty-string, string> $headers extra request headers (auth, user agent, …)
     *
     * @throws HttpException on transport failure
     *
     * @psalm-suppress MissingAbstractPureAnnotation
     *         the network call is the entire point of this method
     */
    public function get(string $url, array $headers = []): HttpResponse;
}
