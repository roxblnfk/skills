<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Source\Http;

use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Util\HttpDownloader;

/**
 * {@see HttpClient} backed by Composer's {@see HttpDownloader}.
 *
 * Wrapping Composer's downloader gives us three things for free:
 *
 * - **Credentials** — `auth.json` / `COMPOSER_AUTH` GitHub tokens and
 *   bearer tokens land in the request automatically. This makes
 *   Composer's auth machinery the single source of truth for
 *   remote-fetch authentication.
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

    /**
     * Build a client whose downloader can actually authenticate.
     *
     * Composer keeps credentials on the **IO**, not the {@see Config}:
     * `auth.json` / `COMPOSER_AUTH` tokens (`github-oauth`,
     * `gitlab-token`, `http-basic`, `bearer`, …) only reach a request
     * after {@see IOInterface::loadConfiguration()} has parsed the
     * config into the IO's authentication store. A bare
     * `new HttpDownloader(new NullIO(), $config)` therefore sends every
     * request anonymously — fine for public repos, but a private GitLab
     * project answers anonymous API calls with `404 Project Not Found`
     * (it never even gets the `401` that would trigger Composer's
     * auth-retry), so the fetch fails no matter how the token is
     * configured.
     *
     * Loading the config into the IO here fixes that. As a bonus,
     * {@see IOInterface::loadConfiguration()} implicitly registers any
     * host that has a `gitlab-token` / `github-oauth` entry into the
     * matching `*-domains` list, so a self-hosted host needs only the
     * token, not a separate `gitlab-domains` entry.
     *
     * A {@see NullIO} is used by default so the fetch stays
     * non-interactive (no credential prompts mid-sync); the stored
     * token is all we need. A malformed token in `auth.json` must not
     * abort discovery/sync, so a load failure degrades to anonymous —
     * the private fetch then surfaces the 404 + auth hint.
     */
    public static function fromConfig(Config $config, ?IOInterface $io = null): self
    {
        $io ??= new NullIO();
        try {
            $io->loadConfiguration($config);
        } catch (\Throwable) {
            // Proceed unauthenticated; a private fetch will report the
            // 404/401 with the adapter's auth hint.
        }
        return new self(new HttpDownloader($io, $config));
    }

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
