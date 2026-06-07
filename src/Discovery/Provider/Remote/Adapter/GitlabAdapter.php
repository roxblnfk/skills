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
 * {@see HostAdapter} for GitLab.com and self-hosted GitLab.
 *
 * The shape mirrors {@see GithubAdapter} — the two VCS hosts differ
 * only in their API surface, not in the adapter's responsibilities:
 *
 * 1. **CLI parse** ({@see self::parseAddInput()}) — turn user input
 *    like `group/project`, `group/project@v1`, or
 *    `https://gitlab.com/group/project` into a {@see ParsedAddInput}.
 *    Unlike GitHub, GitLab supports **nested groups**, so the package
 *    path may carry more than two segments
 *    (`group/subgroup/project`).
 * 2. **Resolve** ({@see self::resolve()}) — read the entry's `ref`
 *    field (or absence) and produce a concrete archive URL + a
 *    concrete ref string, walking the ref cascade (highest stable tag
 *    → highest prerelease → default branch HEAD) when needed.
 * 3. **API base** — handle the host vs API URL mapping:
 *    `https://gitlab.com` ⇒ `https://gitlab.com/api/v4`; self-hosted
 *    GitLab uses `<host>/api/v4`.
 *
 * GitLab's API addresses a project by its URL-encoded full path
 * (`group%2Fproject`), not by an `owner/repo` path segment the way
 * GitHub does — so every endpoint here interpolates
 * {@see self::projectId()} rather than the raw package string. The
 * archive is fetched from the `repository/archive.zip?sha=<ref>`
 * endpoint, which (like GitHub's zipball) yields a zip with a single
 * top-level directory the fetcher unwraps.
 *
 * Authentication is purely a property of the {@see HttpClient}
 * implementation that is injected — no credentials live in the
 * adapter itself.
 *
 * @psalm-suppress MissingImmutableAnnotation
 *         depends on an impure {@see HttpClient}; readonly-ness is local to this class
 */
final readonly class GitlabAdapter implements HostAdapter
{
    public const ID = ProviderId::GITLAB;

    /** Public GitLab.com. */
    public const DEFAULT_HOST = 'https://gitlab.com';

    /** Public GitLab.com API base. */
    public const DEFAULT_API_BASE = 'https://gitlab.com/api/v4';

    /**
     * GitLab caps `per_page` at 100; the page size is enough for any
     * reasonably-sized project's recent tag history. The adapter does
     * not paginate — older history is irrelevant for choosing "the
     * latest stable".
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
     * - `group/project` — shorthand, defaults to `gitlab.com`.
     * - `group/subgroup/project` — nested groups are allowed.
     * - `group/project@ref` — shorthand with explicit ref.
     * - `https://gitlab.com/group/project` — full URL.
     * - `https://gitlab.com/group/project.git` — `.git` suffix tolerated.
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
            throw new \InvalidArgumentException('gitlab adapter: input must not be empty');
        }

        $host = $hostOverride;
        $ref = $refOverride;
        $packageRaw = null;

        // Case 1: full URL. Try to extract host + project path from it.
        // The path is captured greedily (minus a trailing `.git` and
        // any query/fragment) because GitLab projects can live under
        // nested groups — `group/subgroup/project` is one valid path.
        // Delimiter is `~` because the pattern's character class
        // contains `#`, which would otherwise close a `#`-delimited
        // regex mid-pattern.
        if (\preg_match('~^(https?://[^/]+)/(.+?)(?:\.git)?(?:[?\#].*)?$~', $input, $m) === 1) {
            $extractedHost = $m[1];
            $packageRaw = $m[2];
            if ($host !== null && $host !== $extractedHost) {
                throw new \InvalidArgumentException(\sprintf(
                    'gitlab adapter: --host=%s conflicts with URL host %s',
                    $host,
                    $extractedHost,
                ));
            }
            // For gitlab.com URLs we leave host implicit so it defaults
            // to DEFAULT_HOST; only self-hosted hosts get an explicit
            // value to keep the stored config terse.
            if ($host === null && $extractedHost !== self::DEFAULT_HOST && $extractedHost !== '') {
                $host = $extractedHost;
            }
        } elseif (\strpos($input, '@') !== false) {
            // Case 2a: shorthand with embedded ref.
            [$pkgPart, $refPart] = \explode('@', $input, 2);
            if ($pkgPart === '' || $refPart === '') {
                throw new \InvalidArgumentException(
                    'gitlab adapter: input "' . $input . '" is not in the form group/project@ref',
                );
            }
            if ($refOverride !== null && $refOverride !== $refPart) {
                throw new \InvalidArgumentException(
                    'gitlab adapter: --ref conflicts with embedded @ref in input',
                );
            }
            $packageRaw = $pkgPart;
            $ref = $refOverride ?? $refPart;
        } else {
            // Case 2b: plain shorthand `group/project`.
            $packageRaw = $input;
        }

        // Strip a leading/trailing slash the URL form can leave behind
        // (e.g. a trailing `/`) before validating the segment shape.
        $packageRaw = \trim($packageRaw, '/');
        if ($packageRaw === '' || !\str_contains($packageRaw, '/')) {
            throw new \InvalidArgumentException(
                'gitlab adapter: package must be group/project, got "' . $input . '"',
            );
        }
        // GitLab allows nested groups, so two-or-more segments are fine;
        // reject only pathological forms with an empty segment
        // (`group//project`, `group/`, `/project`).
        $segments = \explode('/', $packageRaw);
        if (\count($segments) < 2) {
            throw new \InvalidArgumentException(
                'gitlab adapter: package must be group/project, got "' . $packageRaw . '"',
            );
        }
        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new \InvalidArgumentException(
                    'gitlab adapter: package must be group/project, got "' . $packageRaw . '"',
                );
            }
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
            throw new RemoteResolveException($entry, 'package is required for gitlab');
        }

        $apiBase = $this->apiBaseFor($entry->host);

        if ($entry->ref === null) {
            return $this->resolveCascade($entry, $apiBase, $package);
        }
        if ($this->refResolver->isCaretConstraint($entry->ref)) {
            return $this->resolveCaret($entry, $apiBase, $package, $entry->ref);
        }
        // Verbatim ref — tag, branch, or SHA. GitLab's archive endpoint
        // accepts any of them as the `sha` query parameter, so we don't
        // need to know which it is at this layer.
        return new RemoteDonorRef(
            url: self::archiveUrl($apiBase, $package, $entry->ref),
            ref: $entry->ref,
        );
    }

    /**
     * Translate the stored `host` field into the API base URL.
     *
     * - Absent / `https://gitlab.com` → `https://gitlab.com/api/v4`.
     * - Anything else → `<host>/api/v4` (the self-hosted convention).
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
        return $trimmed . '/api/v4';
    }

    /**
     * GitLab addresses a project by its URL-encoded full path. The
     * slash between `group` and `project` becomes `%2F`; nested groups
     * encode every separator the same way.
     *
     * @param non-empty-string $package
     *
     * @return non-empty-string
     *
     * @psalm-pure
     */
    private static function projectId(string $package): string
    {
        /** @var non-empty-string */
        return \rawurlencode($package);
    }

    /**
     * @param non-empty-string $apiBase
     * @param non-empty-string $package group/project
     * @param non-empty-string $ref
     *
     * @return non-empty-string
     *
     * @psalm-pure
     */
    private static function archiveUrl(string $apiBase, string $package, string $ref): string
    {
        // `/projects/{id}/repository/archive.zip?sha={ref}` is the
        // documented endpoint; it streams a zip whose single top-level
        // directory is `{project}-{ref}-{sha}`, which the fetcher
        // unwraps just like a GitHub zipball.
        return \sprintf(
            '%s/projects/%s/repository/archive.zip?sha=%s',
            $apiBase,
            self::projectId($package),
            \rawurlencode($ref),
        );
    }

    /**
     * @param non-empty-string $apiBase
     * @param non-empty-string $package group/project
     */
    private function resolveCascade(RemoteEntry $entry, string $apiBase, string $package): RemoteDonorRef
    {
        $tags = $this->listTags($entry, $apiBase, $package);

        $stable = $this->refResolver->pickHighestStable($tags);
        if ($stable !== null) {
            return new RemoteDonorRef(
                url: self::archiveUrl($apiBase, $package, $stable),
                ref: $stable,
            );
        }

        $anySemver = $this->refResolver->pickHighestAny($tags);
        if ($anySemver !== null) {
            return new RemoteDonorRef(
                url: self::archiveUrl($apiBase, $package, $anySemver),
                ref: $anySemver,
            );
        }

        $branch = $this->getDefaultBranch($entry, $apiBase, $package);
        return new RemoteDonorRef(
            url: self::archiveUrl($apiBase, $package, $branch),
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
            url: self::archiveUrl($apiBase, $package, $match),
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
        $url = \sprintf(
            '%s/projects/%s/repository/tags?per_page=%d',
            $apiBase,
            self::projectId($package),
            self::TAGS_PER_PAGE,
        );
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
     * Fetch `default_branch` from `/projects/{id}`. Used by the
     * cascade's third step when no tags are present.
     *
     * @param non-empty-string $apiBase
     * @param non-empty-string $package
     *
     * @return non-empty-string
     */
    private function getDefaultBranch(RemoteEntry $entry, string $apiBase, string $package): string
    {
        $url = \sprintf('%s/projects/%s', $apiBase, self::projectId($package));
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
                'Accept' => 'application/json',
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
