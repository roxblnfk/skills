<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote\Http;

use Composer\Downloader\TransportException;
use Composer\Util\HttpDownloader;

/**
 * {@see HttpClient} backed by Composer's {@see HttpDownloader}.
 *
 * Wrapping Composer's downloader gives us three things for free:
 *
 * - **Credentials** — `auth.json` / `COMPOSER_AUTH` GitHub tokens and
 *   bearer tokens land in the request automatically. Spec §5.3 makes
 *   this the single source of truth for remote-fetch authentication.
 * - **HTTPS / SSL handling** — Composer already handles certificate
 *   verification, system root CAs, and proxy environment variables.
 * - **Retry / TLS fallback** — Composer's downloader handles 5xx
 *   retries and TLS 1.2/1.3 negotiation, both of which would be
 *   painful to reproduce.
 *
 * This wrapper deliberately stays minimal: status code, body, and
 * a normalised lower-cased header map. Streaming is out of scope.
 *
 * @psalm-suppress MissingImmutableAnnotation
 *         {@see HttpDownloader} is mutable Composer-internal state
 */
final readonly class ComposerHttpClient implements HttpClient
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private HttpDownloader $downloader,
    ) {}

    #[\Override]
    public function get(string $url, array $headers = []): HttpResponse
    {
        $options = [];
        if ($headers !== []) {
            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }
            $options['http']['header'] = $headerLines;
        }

        try {
            $response = $this->downloader->get($url, $options);
        } catch (TransportException $e) {
            $msg = $e->getMessage();
            throw new HttpException($url, $msg !== '' ? $msg : 'transport failure', $e);
        }

        $headerMap = [];
        /** @var array<int, string>|null $responseHeaders */
        $responseHeaders = $response->getHeaders();
        if ($responseHeaders !== null) {
            foreach ($responseHeaders as $line) {
                $colon = \strpos($line, ':');
                if ($colon === false) {
                    continue;
                }
                $name = \strtolower(\trim(\substr($line, 0, $colon)));
                $value = \trim(\substr($line, $colon + 1));
                if ($name !== '') {
                    $headerMap[$name] = $value;
                }
            }
        }

        return new HttpResponse(
            statusCode: $response->getStatusCode(),
            body: (string) $response->getBody(),
            headers: $headerMap,
        );
    }
}
