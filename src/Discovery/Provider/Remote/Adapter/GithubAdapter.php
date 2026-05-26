<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote\Adapter;

use LLM\Skills\Config\RemoteEntry;
use LLM\Skills\Discovery\Provider\ProviderId;
use LLM\Skills\Discovery\Provider\Remote\Http\HttpClient;
use LLM\Skills\Discovery\Provider\Remote\Http\HttpException;
use LLM\Skills\Discovery\Provider\Remote\Http\HttpResponse;
use LLM\Skills\Discovery\Provider\Remote\RefResolver;
use LLM\Skills\Discovery\Provider\Remote\RemoteDonorRef;

/**
 * {@see HostAdapter} for GitHub.com and GitHub Enterprise.
 *
 * The adapter is the smallest amount of code that lets the system
 * fetch a versioned tarball from a GitHub repo. It does three things:
 *
 * 1. **CLI parse** ({@see self::parseAddInput()}) — turn user input
 *    like `owner/repo`, `owner/repo@v1`, or
 *    `https://github.com/owner/repo` into a {@see ParsedAddInput}.
 * 2. **Resolve** ({@see self::resolve()}) — read the entry's `ref`
 *    field (or absence) and produce a concrete archive URL + a
 *    concrete ref string, walking the ref cascade (highest stable tag
 *    → highest prerelease → default branch HEAD) when needed.
 * 3. **API base** — handle the host vs API URL mapping:
 *    `https://github.com` ⇒ `https://api.github.com`; GHE uses
 *    `<host>/api/v3`.
 *
 * Authentication is purely a property of the {@see HttpClient}
 * implementation that is injected — no credentials live in the
 * adapter itself.
 *
 * @psalm-suppress MissingImmutableAnnotation
 *         depends on an impure {@see HttpClient}; readonly-ness is local to this class
 */
final readonly class GithubAdapter implements HostAdapter
{
    public const ID = ProviderId::GITHUB;

    /** Public GitHub.com. */
    public const DEFAULT_HOST = 'https://github.com';

    /** Public GitHub.com API base. */
    public const DEFAULT_API_BASE = 'https://api.github.com';

    /**
     * GitHub recommends per_page=100; the page size is enough for
     * any reasonably-sized repo's recent tag history. The adapter
     * does not paginate — older history is irrelevant for choosing
     * "the latest stable".
     */
    private const TAGS_PER_PAGE = 100;

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private HttpClient $http,
        private RefResolver $refResolver = new RefResolver(),
    ) {}

    /**
     * @psalm-pure
     */
    #[\Override]
    public function id(): string
    {
        return self::ID;
    }

    /**
     * @psalm-pure
     */
    #[\Override]
    public function defaultHost(): string
    {
        return self::DEFAULT_HOST;
    }

    /**
     * Accepted input grammars:
     *
     * - `owner/repo` — shorthand, defaults to `github.com`.
     * - `owner/repo@ref` — shorthand with explicit ref.
     * - `https://github.com/owner/repo` — full URL.
     * - `https://github.com/owner/repo.git` — `.git` suffix tolerated.
     *
     * `$refOverride` and the embedded `@ref` cannot both be set —
     * conflicting ref sources are rejected with an error.
     *
     * @psalm-suppress PossiblyUndefinedArrayOffset preg_match capture groups are populated when the match succeeds
     *
     * @psalm-pure
     */
    #[\Override]
    public function parseAddInput(
        string $input,
        ?string $hostOverride = null,
        ?string $refOverride = null,
    ): ParsedAddInput {
        if ($input === '') {
            throw new \InvalidArgumentException('github adapter: input must not be empty');
        }

        $host = $hostOverride;
        $ref = $refOverride;
        $packageRaw = null;

        // Case 1: full URL. Try to extract host + owner/repo from it.
        // Delimiter is `~` because the pattern's character class
        // contains `#`, which would otherwise close a `#`-delimited
        // regex mid-pattern.
        if (\preg_match('~^(https?://[^/]+)/([^/]+/[^/?\#]+?)(?:\.git)?(?:[?\#].*)?$~', $input, $m) === 1) {
            $extractedHost = $m[1];
            $packageRaw = $m[2];
            if ($host !== null && $host !== $extractedHost) {
                throw new \InvalidArgumentException(\sprintf(
                    'github adapter: --host=%s conflicts with URL host %s',
                    $host,
                    $extractedHost,
                ));
            }
            // For github.com URLs we leave host implicit so it
            // defaults to DEFAULT_HOST; only GHE hosts get an explicit
            // value to keep the stored config terse.
            if ($host === null && $extractedHost !== self::DEFAULT_HOST && $extractedHost !== '') {
                $host = $extractedHost;
            }
        } elseif (\strpos($input, '@') !== false) {
            // Case 2a: shorthand with embedded ref.
            [$pkgPart, $refPart] = \explode('@', $input, 2);
            if ($pkgPart === '' || $refPart === '') {
                throw new \InvalidArgumentException(
                    'github adapter: input "' . $input . '" is not in the form owner/repo@ref',
                );
            }
            if ($refOverride !== null && $refOverride !== $refPart) {
                throw new \InvalidArgumentException(
                    'github adapter: --ref conflicts with embedded @ref in input',
                );
            }
            $packageRaw = $pkgPart;
            $ref = $refOverride ?? $refPart;
        } else {
            // Case 2b: plain shorthand `owner/repo`.
            $packageRaw = $input;
        }

        if ($packageRaw === '' || !\str_contains($packageRaw, '/')) {
            throw new \InvalidArgumentException(
                'github adapter: package must be owner/repo, got "' . $input . '"',
            );
        }
        // Reject pathological forms like `owner/` or `/repo`.
        $segments = \explode('/', $packageRaw);
        if (\count($segments) !== 2 || $segments[0] === '' || $segments[1] === '') {
            throw new \InvalidArgumentException(
                'github adapter: package must be owner/repo, got "' . $packageRaw . '"',
            );
        }
        /** @var non-empty-string $packageRaw */

        return new ParsedAddInput(
            from: self::ID,
            package: $packageRaw,
            url: null,
            host: $host,
            ref: $ref,
        );
    }

    #[\Override]
    public function resolve(RemoteEntry $entry): RemoteDonorRef
    {
        if ($entry->from !== self::ID) {
            throw new RemoteResolveException(
                $entry,
                'adapter id mismatch (expected "' . self::ID . '", got "' . $entry->from . '")',
            );
        }
        $package = $entry->package;
        if ($package === null) {
            throw new RemoteResolveException($entry, 'package is required for github');
        }

        $apiBase = $this->apiBaseFor($entry->host);

        if ($entry->ref === null) {
            return $this->resolveCascade($entry, $apiBase, $package);
        }
        if ($this->refResolver->isCaretConstraint($entry->ref)) {
            return $this->resolveCaret($entry, $apiBase, $package, $entry->ref);
        }
        // Verbatim ref — tag, branch, or SHA. GitHub's zipball
        // endpoint accepts any of them, so we don't need to know
        // which it is at this layer.
        return new RemoteDonorRef(
            url: self::zipballUrl($apiBase, $package, $entry->ref),
            ref: $entry->ref,
        );
    }

    /**
     * Translate the stored `host` field into the API base URL.
     *
     * - Absent / `https://github.com` → `https://api.github.com`.
     * - Anything else → `<host>/api/v3` (the GHE convention).
     *
     * @param non-empty-string|null $host
     *
     * @return non-empty-string
     *
     * @psalm-pure
     */
    public function apiBaseFor(?string $host): string
    {
        if ($host === null || $host === self::DEFAULT_HOST) {
            return self::DEFAULT_API_BASE;
        }
        $trimmed = \rtrim($host, '/');
        if ($trimmed === '') {
            return self::DEFAULT_API_BASE;
        }
        return $trimmed . '/api/v3';
    }

    /**
     * @param non-empty-string $apiBase
     * @param non-empty-string $package
     * @param non-empty-string $ref
     *
     * @return non-empty-string
     *
     * @psalm-pure
     */
    private static function zipballUrl(string $apiBase, string $package, string $ref): string
    {
        // `/repos/{owner}/{repo}/zipball/{ref}` is the documented
        // endpoint; it 302-redirects to a codeload URL that carries
        // the same tag/branch content. The fetcher follows redirects.
        return \sprintf('%s/repos/%s/zipball/%s', $apiBase, $package, \rawurlencode($ref));
    }

    /**
     * @param non-empty-string $apiBase
     * @param non-empty-string $package owner/repo
     */
    private function resolveCascade(RemoteEntry $entry, string $apiBase, string $package): RemoteDonorRef
    {
        $tags = $this->listTags($entry, $apiBase, $package);

        $stable = $this->refResolver->pickHighestStable($tags);
        if ($stable !== null) {
            return new RemoteDonorRef(
                url: self::zipballUrl($apiBase, $package, $stable),
                ref: $stable,
            );
        }

        $anySemver = $this->refResolver->pickHighestAny($tags);
        if ($anySemver !== null) {
            return new RemoteDonorRef(
                url: self::zipballUrl($apiBase, $package, $anySemver),
                ref: $anySemver,
            );
        }

        $branch = $this->getDefaultBranch($entry, $apiBase, $package);
        return new RemoteDonorRef(
            url: self::zipballUrl($apiBase, $package, $branch),
            ref: $branch,
        );
    }

    /**
     * @param non-empty-string $apiBase
     * @param non-empty-string $package
     * @param non-empty-string $constraint
     */
    private function resolveCaret(
        RemoteEntry $entry,
        string $apiBase,
        string $package,
        string $constraint,
    ): RemoteDonorRef {
        $tags = $this->listTags($entry, $apiBase, $package);
        $match = $this->refResolver->resolveCaret($constraint, $tags);
        if ($match === null) {
            throw new RemoteResolveException(
                $entry,
                'no tag in ' . $package . ' matches constraint ' . $constraint,
            );
        }
        return new RemoteDonorRef(
            url: self::zipballUrl($apiBase, $package, $match),
            ref: $match,
        );
    }

    /**
     * Fetch tag names for `$package` from the API. Returns at most
     * {@see self::TAGS_PER_PAGE} entries — sufficient for picking the
     * "latest" anything.
     *
     * @param non-empty-string $apiBase
     * @param non-empty-string $package
     *
     * @return list<non-empty-string>
     */
    private function listTags(RemoteEntry $entry, string $apiBase, string $package): array
    {
        $url = \sprintf('%s/repos/%s/tags?per_page=%d', $apiBase, $package, self::TAGS_PER_PAGE);
        $response = $this->getOrThrow($entry, $url);
        $decoded = $this->decodeJson($entry, $response, $url);
        if (!\is_array($decoded) || !\array_is_list($decoded)) {
            throw new RemoteResolveException($entry, $url . ' returned a non-array body');
        }

        $tags = [];
        /** @var mixed $item */
        foreach ($decoded as $item) {
            if (!\is_array($item)) {
                continue;
            }
            /** @var mixed $name */
            $name = $item['name'] ?? null;
            if (!\is_string($name) || $name === '') {
                continue;
            }
            $tags[] = $name;
        }
        return $tags;
    }

    /**
     * Fetch `default_branch` from `/repos/{owner}/{repo}`. Used by
     * the cascade's third step when no tags are present.
     *
     * @param non-empty-string $apiBase
     * @param non-empty-string $package
     *
     * @return non-empty-string
     */
    private function getDefaultBranch(RemoteEntry $entry, string $apiBase, string $package): string
    {
        $url = \sprintf('%s/repos/%s', $apiBase, $package);
        $response = $this->getOrThrow($entry, $url);
        $decoded = $this->decodeJson($entry, $response, $url);
        if (!\is_array($decoded)) {
            throw new RemoteResolveException($entry, $url . ' returned a non-object body');
        }
        /** @var mixed $branch */
        $branch = $decoded['default_branch'] ?? null;
        if (!\is_string($branch) || $branch === '') {
            throw new RemoteResolveException(
                $entry,
                $url . ' did not return a non-empty default_branch',
            );
        }
        return $branch;
    }

    /**
     * Wrap an HTTP GET with adapter-flavoured exceptions: transport
     * failures become {@see RemoteResolveException}; non-success
     * statuses are reported alongside the URL so the user sees
     * exactly which call returned 404 / 403 / 5xx.
     *
     * @param non-empty-string $url
     */
    private function getOrThrow(RemoteEntry $entry, string $url): HttpResponse
    {
        try {
            $response = $this->http->get($url, [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'llm-skills',
            ]);
        } catch (HttpException $e) {
            $msg = $e->getMessage();
            throw new RemoteResolveException($entry, $msg !== '' ? $msg : 'transport failure', $e);
        }

        if (!$response->isSuccess()) {
            /** @var non-empty-string $reason */
            $reason = \sprintf('%s returned HTTP %d', $url, $response->statusCode);
            throw new RemoteResolveException($entry, $reason);
        }
        return $response;
    }

    /**
     * @param non-empty-string $url
     *
     * @psalm-pure
     */
    private function decodeJson(RemoteEntry $entry, HttpResponse $response, string $url): mixed
    {
        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($response->body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RemoteResolveException(
                $entry,
                $url . ' returned invalid JSON: ' . $e->getMessage(),
                $e,
            );
        }
        return $decoded;
    }
}
