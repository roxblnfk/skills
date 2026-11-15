<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Source\Adapter;

use LLM\Skills\Config\SourceEntry;
use LLM\Skills\Discovery\Provider\ProviderId;
use LLM\Skills\Discovery\Provider\Source\Http\HttpClient;
use LLM\Skills\Discovery\Provider\Source\Http\HttpException;
use LLM\Skills\Discovery\Provider\Source\Http\HttpResponse;
use LLM\Skills\Discovery\Provider\Source\RefResolver;
use LLM\Skills\Discovery\Provider\Source\RemoteDonorRef;

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

    private const HISTORY_PER_PAGE = 100;

    /**
     * A branch with a longer push history since the cutoff fails.
     */
    private const HISTORY_MAX_PAGES = 10;

    private const DELETED_SHA = '0000000000000000000000000000000000000000';
    private const MAX_TAG_CANDIDATES = 10;

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

    /**
     * Under a cooldown only a commit that was on the default branch at the
     * cutoff is fetched, by its SHA; a branch named in `ref` is fetched at the
     * commit it pointed at then. Both come from GitHub's records (the
     * repository activity log), never from commit or release dates, which the
     * pusher sets or keeps when a tag is moved.
     */
    #[\Override]
    public function resolve(SourceEntry $entry, ?\DateTimeImmutable $notAfter = null): RemoteDonorRef
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
            return $this->resolveCascade($entry, $apiBase, $package, $notAfter);
        }
        if ($this->refResolver->isConstraint($entry->ref)) {
            return $this->resolveVersionConstraint($entry, $apiBase, $package, $entry->ref, $notAfter);
        }
        if ($notAfter !== null && !self::isSha($entry->ref)) {
            return $this->resolveVerbatimUnderCooldown($entry, $apiBase, $package, $entry->ref, $notAfter);
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
     * @return non-empty-string|null
     *
     * @psalm-pure
     */
    private static function nextPageUrl(HttpResponse $response): ?string
    {
        $link = $response->header('Link');
        if ($link === null || \preg_match('/<([^>]+)>;\s*rel="next"/', $link, $match) !== 1 || $match[1] === '') {
            return null;
        }
        return $match[1];
    }

    /**
     * @param non-empty-string $subject
     *
     * @return non-empty-string
     *
     * @psalm-pure
     */
    private static function noCooledTagReason(string $subject, \DateTimeImmutable $notAfter): string
    {
        return \sprintf(
            'cooldown: %s was on the default branch at %s',
            $subject,
            self::formatTime($notAfter),
        );
    }

    /**
     * @return non-empty-string
     *
     * @psalm-pure
     */
    private static function formatTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC';
    }

    /**
     * @psalm-assert-if-true non-empty-string $value
     *
     * @psalm-pure
     */
    private static function isSha(mixed $value): bool
    {
        return \is_string($value) && \preg_match('/^[0-9a-f]{40}$/i', $value) === 1;
    }

    /**
     * @param non-empty-string $sha
     *
     * @return non-empty-string
     *
     * @psalm-pure
     */
    private static function existedAt(SourceEntry $entry, string $branch, string $sha, \DateTimeImmutable $notAfter): string
    {
        if ($sha === self::DELETED_SHA) {
            throw new RemoteResolveException($entry, \sprintf(
                'cooldown: branch "%s" did not exist at %s',
                $branch,
                self::formatTime($notAfter),
            ));
        }
        return $sha;
    }

    /**
     * @param non-empty-string $apiBase
     * @param non-empty-string $package owner/repo
     */
    private function resolveCascade(
        SourceEntry $entry,
        string $apiBase,
        string $package,
        ?\DateTimeImmutable $notAfter,
    ): RemoteDonorRef {
        $tags = $this->listTags($entry, $apiBase, $package);

        // The tier is chosen from all tags, as without a cooldown: when all
        // its tags are too new the source fails instead of falling through
        // to unreleased branch code.
        $pickers = [
            $this->refResolver->pickHighestStable(...),
            $this->refResolver->pickHighestAny(...),
        ];
        foreach ($pickers as $pick) {
            $tag = $pick($tags);
            if ($tag === null) {
                continue;
            }
            if ($notAfter !== null) {
                [$tag, $sha] = $this->pickCooledTag($entry, $apiBase, $package, $tags, $pick, $notAfter, 'no tag');
                return new RemoteDonorRef(url: self::zipballUrl($apiBase, $package, $sha), ref: $tag);
            }
            return new RemoteDonorRef(
                url: self::zipballUrl($apiBase, $package, $tag),
                ref: $tag,
            );
        }

        $branch = $this->getDefaultBranch($entry, $apiBase, $package);
        if ($notAfter !== null) {
            return $this->branchStateAt($entry, $apiBase, $package, $branch, $notAfter);
        }
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
    private function resolveVersionConstraint(
        SourceEntry $entry,
        string $apiBase,
        string $package,
        string $constraint,
        ?\DateTimeImmutable $notAfter,
    ): RemoteDonorRef {
        $tags = $this->listTags($entry, $apiBase, $package);
        $match = $this->refResolver->resolveConstraint($constraint, $tags);
        if ($match === null) {
            throw new RemoteResolveException(
                $entry,
                'no tag in ' . $package . ' matches constraint ' . $constraint,
            );
        }
        if ($notAfter !== null) {
            [$tag, $sha] = $this->pickCooledTag(
                $entry,
                $apiBase,
                $package,
                $tags,
                fn(array $candidates): ?string => $this->refResolver->resolveConstraint($constraint, $candidates),
                $notAfter,
                'no tag matching ' . $constraint,
            );
            return new RemoteDonorRef(url: self::zipballUrl($apiBase, $package, $sha), ref: $tag);
        }
        return new RemoteDonorRef(
            url: self::zipballUrl($apiBase, $package, $match),
            ref: $match,
        );
    }

    /**
     * A branch name is dated by the activity log. Any other ref — a tag, a
     * SHA, `HEAD`, `refs/...` — must point at a commit that was on the default
     * branch at the cutoff. The branch is checked first, so a tag named like
     * a branch cannot replace the branch's dated state.
     *
     * @param non-empty-string $apiBase
     * @param non-empty-string $package
     * @param non-empty-string $ref
     */
    private function resolveVerbatimUnderCooldown(
        SourceEntry $entry,
        string $apiBase,
        string $package,
        string $ref,
        \DateTimeImmutable $notAfter,
    ): RemoteDonorRef {
        if (!self::isSha($ref) && $this->branchHead($entry, $apiBase, $package, $ref) !== null) {
            return $this->branchStateAt($entry, $apiBase, $package, $ref, $notAfter);
        }

        $branchSha = $this->defaultBranchShaAt($entry, $apiBase, $package, $notAfter);
        $sha = $this->commitIn($entry, $apiBase, $package, $ref, $branchSha);
        if ($sha === null) {
            throw new RemoteResolveException($entry, \sprintf(
                'cooldown: "%s" was not on the default branch at %s',
                $ref,
                self::formatTime($notAfter),
            ));
        }
        return new RemoteDonorRef(url: self::zipballUrl($apiBase, $package, $sha), ref: $ref);
    }

    /**
     * Walks candidates from the highest down and returns the first one whose
     * commit was on the default branch at the cutoff, with that commit to
     * fetch: a tag can be moved after the check, its commit cannot.
     *
     * @param non-empty-string $apiBase
     * @param non-empty-string $package
     * @param list<non-empty-string> $tags
     * @param \Closure(list<non-empty-string>): (non-empty-string|null) $pick
     * @param non-empty-string $subject
     *
     * @return array{non-empty-string, non-empty-string} tag name and its commit SHA
     */
    private function pickCooledTag(
        SourceEntry $entry,
        string $apiBase,
        string $package,
        array $tags,
        \Closure $pick,
        \DateTimeImmutable $notAfter,
        string $subject,
    ): array {
        $branchSha = $this->defaultBranchShaAt($entry, $apiBase, $package, $notAfter);

        $candidates = $tags;
        for ($checked = 0; ($tag = $pick($candidates)) !== null; ++$checked) {
            if ($checked === self::MAX_TAG_CANDIDATES) {
                throw new RemoteResolveException($entry, \sprintf(
                    '%s (checked the %d highest candidates)',
                    self::noCooledTagReason($subject, $notAfter),
                    self::MAX_TAG_CANDIDATES,
                ));
            }
            $sha = $this->commitIn($entry, $apiBase, $package, 'refs/tags/' . $tag, $branchSha);
            if ($sha !== null) {
                return [$tag, $sha];
            }
            $candidates = \array_values(\array_filter(
                $candidates,
                static fn(string $candidate): bool => $candidate !== $tag,
            ));
        }

        throw new RemoteResolveException($entry, self::noCooledTagReason($subject, $notAfter));
    }

    /**
     * @param non-empty-string $apiBase
     * @param non-empty-string $package
     *
     * @return non-empty-string
     */
    private function defaultBranchShaAt(
        SourceEntry $entry,
        string $apiBase,
        string $package,
        \DateTimeImmutable $notAfter,
    ): string {
        $branch = $this->getDefaultBranch($entry, $apiBase, $package);
        return $this->branchShaAt($entry, $apiBase, $package, $branch, $notAfter);
    }

    /**
     * GitHub resolves `$ref` (a SHA, short or full, a tag, any ref name).
     *
     * @param non-empty-string $apiBase
     * @param non-empty-string $package
     * @param non-empty-string $ref
     * @param non-empty-string $head
     *
     * @return non-empty-string|null the commit `$ref` points at when it is `$head` or one of its ancestors
     */
    private function commitIn(SourceEntry $entry, string $apiBase, string $package, string $ref, string $head): ?string
    {
        $url = \sprintf('%s/repos/%s/compare/%s...%s?per_page=1', $apiBase, $package, \rawurlencode($ref), $head);
        /** @var mixed $decoded */
        $decoded = $this->decodeJson($entry, $this->getOrThrow($entry, $url), $url);
        $status = \is_array($decoded) ? ($decoded['status'] ?? null) : null;
        if (!\is_string($status)) {
            throw new RemoteResolveException($entry, $url . ' returned no comparison status');
        }
        if ($status !== 'ahead' && $status !== 'identical') {
            return null;
        }
        /** @var mixed $base */
        $base = \is_array($decoded) ? ($decoded['base_commit'] ?? null) : null;
        /** @var mixed $sha */
        $sha = \is_array($base) ? ($base['sha'] ?? null) : null;
        if (!self::isSha($sha)) {
            throw new RemoteResolveException($entry, $url . ' returned no base commit SHA');
        }
        return $sha;
    }

    /**
     * @param non-empty-string $apiBase
     * @param non-empty-string $package
     * @param non-empty-string $branch
     */
    private function branchStateAt(
        SourceEntry $entry,
        string $apiBase,
        string $package,
        string $branch,
        \DateTimeImmutable $notAfter,
    ): RemoteDonorRef {
        $sha = $this->branchShaAt($entry, $apiBase, $package, $branch, $notAfter);
        return new RemoteDonorRef(url: self::zipballUrl($apiBase, $package, $sha), ref: $sha);
    }

    /**
     * @param non-empty-string $apiBase
     * @param non-empty-string $package
     * @param non-empty-string $branch
     *
     * @return non-empty-string
     */
    private function branchShaAt(
        SourceEntry $entry,
        string $apiBase,
        string $package,
        string $branch,
        \DateTimeImmutable $notAfter,
    ): string {
        $url = \sprintf(
            '%s/repos/%s/activity?ref=%s&direction=desc&per_page=%d',
            $apiBase,
            $package,
            \rawurlencode('refs/heads/' . $branch),
            self::HISTORY_PER_PAGE,
        );
        $oldestBefore = null;

        for ($page = 0; $url !== null; ++$page) {
            if ($page === self::HISTORY_MAX_PAGES) {
                throw new RemoteResolveException($entry, \sprintf(
                    'cooldown: the push history of branch "%s" since %s is longer than %d pages',
                    $branch,
                    self::formatTime($notAfter),
                    self::HISTORY_MAX_PAGES,
                ));
            }

            $response = $this->getOrThrow($entry, $url);
            foreach ($this->decodeList($entry, $response, $url) as $event) {
                $timestamp = $event['timestamp'] ?? null;
                $before = $event['before'] ?? null;
                $after = $event['after'] ?? null;
                if (!\is_string($timestamp) || !self::isSha($before) || !self::isSha($after)) {
                    throw new RemoteResolveException($entry, $url . ' returned an activity event without timestamp or SHAs');
                }
                if ($this->parseTimestamp($entry, $timestamp, $url) <= $notAfter) {
                    return self::existedAt($entry, $branch, $after, $notAfter);
                }
                $oldestBefore = $before;
            }

            $url = self::nextPageUrl($response);
        }

        // The log does not reach back to the cutoff (it starts in 2023 and is
        // empty for a branch untouched since): the branch held the state the
        // oldest recorded event moved it from, or its head if nothing moved it.
        $sha = $oldestBefore ?? $this->branchHead($entry, $apiBase, $package, $branch);
        if ($sha === null) {
            throw new RemoteResolveException($entry, \sprintf('branch "%s" does not exist', $branch));
        }
        return self::existedAt($entry, $branch, $sha, $notAfter);
    }

    /**
     * `matching-refs` answers 200 with the refs starting with the prefix, so
     * a missing branch needs no 404 handling.
     *
     * @param non-empty-string $apiBase
     * @param non-empty-string $package
     * @param non-empty-string $branch
     *
     * @return non-empty-string|null the commit the branch points at, `null` when there is no such branch
     */
    private function branchHead(SourceEntry $entry, string $apiBase, string $package, string $branch): ?string
    {
        $url = \sprintf('%s/repos/%s/git/matching-refs/heads/%s', $apiBase, $package, \rawurlencode($branch));
        foreach ($this->decodeList($entry, $this->getOrThrow($entry, $url), $url) as $item) {
            if (($item['ref'] ?? null) !== 'refs/heads/' . $branch) {
                continue;
            }
            /** @var mixed $object */
            $object = $item['object'] ?? null;
            $sha = \is_array($object) ? ($object['sha'] ?? null) : null;
            if (!self::isSha($sha)) {
                throw new RemoteResolveException($entry, $url . ' returned a ref without a commit SHA');
            }
            return $sha;
        }
        return null;
    }

    /**
     * @param non-empty-string $url
     *
     * @return list<array<array-key, mixed>>
     *
     * @psalm-mutation-free
     */
    private function decodeList(SourceEntry $entry, HttpResponse $response, string $url): array
    {
        $decoded = $this->decodeJson($entry, $response, $url);
        if (!\is_array($decoded) || !\array_is_list($decoded)) {
            throw new RemoteResolveException($entry, $url . ' returned a non-array body');
        }
        $items = [];
        /** @var mixed $item */
        foreach ($decoded as $item) {
            if (\is_array($item)) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * @param non-empty-string $url
     */
    private function parseTimestamp(SourceEntry $entry, string $value, string $url): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new RemoteResolveException($entry, $url . ' returned an invalid timestamp: ' . $value, $e);
        }
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
    private function listTags(SourceEntry $entry, string $apiBase, string $package): array
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
    private function getDefaultBranch(SourceEntry $entry, string $apiBase, string $package): string
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
    private function getOrThrow(SourceEntry $entry, string $url): HttpResponse
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
    private function decodeJson(SourceEntry $entry, HttpResponse $response, string $url): mixed
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
