<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider\Source\Adapter;

use LLM\Skills\Discovery\Provider\Source\Http\HttpClient;
use LLM\Skills\Discovery\Provider\Source\Http\HttpException;
use LLM\Skills\Discovery\Provider\Source\Http\HttpResponse;

/**
 * Stub HttpClient backed by a URL → response map. Throws an
 * informative error when the adapter hits a URL the test did not
 * stub — catches "unstubbed call" mistakes loudly.
 *
 * Shared by the host-adapter unit tests ({@see GithubAdapterTest},
 * {@see GitlabAdapterTest}) so they can drive `resolve()` without any
 * real HTTP traffic.
 *
 * @internal
 */
final class InMemoryHttpClient implements HttpClient
{
    private int $calls = 0;

    /**
     * @param array<string, HttpResponse|HttpException> $responses
     */
    public function __construct(
        private readonly array $responses,
    ) {}

    #[\Override]
    public function get(string $url, array $headers = []): HttpResponse
    {
        ++$this->calls;
        if (!isset($this->responses[$url])) {
            throw new \LogicException('unstubbed URL in InMemoryHttpClient: ' . $url);
        }
        $resp = $this->responses[$url];
        if ($resp instanceof HttpException) {
            throw $resp;
        }
        return $resp;
    }

    public function callCount(): int
    {
        return $this->calls;
    }
}
