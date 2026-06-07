<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider\Remote\Adapter;

use LLM\Skills\Config\RemoteEntry;
use LLM\Skills\Discovery\Provider\Remote\Adapter\GitlabAdapter;
use LLM\Skills\Discovery\Provider\Remote\Adapter\ParsedAddInput;
use LLM\Skills\Discovery\Provider\Remote\Adapter\RemoteResolveException;
use LLM\Skills\Discovery\Provider\Remote\Http\HttpException;
use LLM\Skills\Discovery\Provider\Remote\Http\HttpResponse;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

/**
 * Unit coverage for {@see GitlabAdapter}. Uses the shared
 * {@see InMemoryHttpClient} stub that returns canned responses keyed
 * by URL — no real HTTP traffic. Verifies both the parsing branch
 * ({@see GitlabAdapter::parseAddInput()}, no IO at all) and the
 * resolve branch ({@see GitlabAdapter::resolve()}, which makes up to
 * two API calls per entry).
 *
 * The cases mirror {@see GithubAdapterTest}; the GitLab-specific
 * differences worth their own probes are the `/api/v4` base, the
 * URL-encoded project id (`group%2Fproject`), nested-group package
 * paths, and the `archive.zip?sha=` endpoint.
 */
#[Test]
#[Covers(GitlabAdapter::class)]
final class GitlabAdapterTest
{
    // ── parseAddInput: shorthand and URLs ──────────────────────────

    public function shorthandGroupSlashProjectIsParsed(): void
    {
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('acme/skills');

        Assert::same($parsed->from, 'gitlab');
        Assert::same($parsed->package, 'acme/skills');
        Assert::same($parsed->host, null);
        Assert::same($parsed->ref, null);
        Assert::same($parsed->url, null);
    }

    public function nestedGroupShorthandIsParsed(): void
    {
        // GitLab supports subgroups — three-or-more segments are valid,
        // unlike GitHub's strict owner/repo.
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('group/subgroup/skills');

        Assert::same($parsed->package, 'group/subgroup/skills');
    }

    public function shorthandWithEmbeddedRef(): void
    {
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('acme/skills@v1.2.3');

        Assert::same($parsed->package, 'acme/skills');
        Assert::same($parsed->ref, 'v1.2.3');
    }

    public function refOverrideTakesPrecedenceOverEmbedded(): void
    {
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('acme/skills@v1.2.3', refOverride: 'v1.2.3');

        Assert::same($parsed->ref, 'v1.2.3');
    }

    public function conflictingEmbeddedRefAndRefOverrideThrows(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('--ref conflicts');

        self::adapter()->parseAddInput('acme/skills@v1.2.3', refOverride: 'v2.0.0');
    }

    public function fullGitlabUrlIsParsed(): void
    {
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('https://gitlab.com/acme/skills');

        Assert::same($parsed->package, 'acme/skills');
        Assert::same($parsed->host, null, 'gitlab.com is left implicit');
    }

    public function fullNestedGroupUrlIsParsed(): void
    {
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('https://gitlab.com/group/subgroup/skills');

        Assert::same($parsed->package, 'group/subgroup/skills');
        Assert::same($parsed->host, null);
    }

    public function fullSelfHostedUrlIsParsedWithExplicitHost(): void
    {
        // Self-hosted URLs MUST keep the host explicit so the adapter
        // can build the right /api/v4 base later. gitlab.com is the
        // only one that gets implicit treatment.
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('https://gitlab.corp.example.com/team/skills');

        Assert::same($parsed->package, 'team/skills');
        Assert::same($parsed->host, 'https://gitlab.corp.example.com');
    }

    public function gitSuffixIsStripped(): void
    {
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('https://gitlab.com/acme/skills.git');

        Assert::same($parsed->package, 'acme/skills');
    }

    public function scpStyleCloneUrlWithSubgroupIsParsed(): void
    {
        // Regression for the SCP-style `git clone` URL of a project that
        // lives in a subgroup — the form users paste verbatim. The SSH
        // host becomes the HTTPS API host (the archive is fetched over
        // the API, not over SSH).
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('git@gitlab.corp.example.com:backend/be-libs/skills.git');

        Assert::same($parsed->package, 'backend/be-libs/skills');
        Assert::same($parsed->host, 'https://gitlab.corp.example.com');
    }

    public function scpStyleGitlabComUrlLeavesHostImplicit(): void
    {
        // gitlab.com is the only host left implicit, mirroring the
        // HTTP-URL behaviour.
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('git@gitlab.com:acme/skills.git');

        Assert::same($parsed->package, 'acme/skills');
        Assert::same($parsed->host, null);
    }

    public function sshUrlIsParsed(): void
    {
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('ssh://git@gitlab.corp.example.com/backend/be-libs/skills.git');

        Assert::same($parsed->package, 'backend/be-libs/skills');
        Assert::same($parsed->host, 'https://gitlab.corp.example.com');
    }

    public function sshUrlWithPortStripsThePort(): void
    {
        // A custom SSH port has no bearing on the HTTPS API host — it
        // must not leak into the stored host value.
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('ssh://git@gitlab.corp.example.com:2222/group/skills.git');

        Assert::same($parsed->package, 'group/skills');
        Assert::same($parsed->host, 'https://gitlab.corp.example.com');
    }

    public function hostOverrideConflictingWithUrlHostThrows(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('conflicts with URL host');

        self::adapter()->parseAddInput(
            'https://gitlab.com/acme/skills',
            hostOverride: 'https://gitlab.corp.example.com',
        );
    }

    public function emptyInputThrows(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('input must not be empty');

        self::adapter()->parseAddInput('');
    }

    public function shorthandWithoutSlashThrows(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('group/project');

        self::adapter()->parseAddInput('flat-name');
    }

    public function shorthandWithEmptySegmentThrows(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('group/project');

        self::adapter()->parseAddInput('group//skills');
    }

    // ── apiBaseFor: gitlab.com vs self-hosted ──────────────────────

    public function apiBaseForGitlabComUsesDefault(): void
    {
        $adapter = self::adapter();

        Assert::same($adapter->apiBaseFor(null), 'https://gitlab.com/api/v4');
        Assert::same($adapter->apiBaseFor('https://gitlab.com'), 'https://gitlab.com/api/v4');
    }

    public function apiBaseForSelfHostedAppendsApiV4(): void
    {
        $adapter = self::adapter();

        Assert::same(
            $adapter->apiBaseFor('https://gitlab.corp.example.com'),
            'https://gitlab.corp.example.com/api/v4',
        );
        // Trailing slash on stored host gets normalised away — both
        // shapes produce the same API base.
        Assert::same(
            $adapter->apiBaseFor('https://gitlab.corp.example.com/'),
            'https://gitlab.corp.example.com/api/v4',
        );
    }

    // ── resolve: explicit ref ──────────────────────────────────────

    public function resolveExplicitTagSkipsApiCalls(): void
    {
        // A literal tag (not a constraint) doesn't need a tag listing
        // — the adapter just hits archive.zip?sha=<tag> directly. The
        // stub would throw on any GET, so reaching the ref unchanged
        // proves no API was called.
        $http = new InMemoryHttpClient([]);
        $adapter = new GitlabAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills', ref: 'v1.2.3'));

        Assert::same($ref->ref, 'v1.2.3');
        Assert::true(\str_ends_with(
            $ref->url,
            '/projects/acme%2Fskills/repository/archive.zip?sha=v1.2.3',
        ));
        Assert::same($http->callCount(), 0);
    }

    public function resolveEncodesNestedGroupProjectId(): void
    {
        // Every path separator in a nested-group package collapses to
        // %2F in the API project id.
        $http = new InMemoryHttpClient([]);
        $adapter = new GitlabAdapter($http);

        $ref = $adapter->resolve(self::entry('group/subgroup/skills', ref: 'main'));

        Assert::true(\str_contains(
            $ref->url,
            '/projects/group%2Fsubgroup%2Fskills/repository/archive.zip?sha=main',
        ));
    }

    public function resolveBranchRefIsTreatedAsLiteral(): void
    {
        $http = new InMemoryHttpClient([]);
        $adapter = new GitlabAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills', ref: 'main'));

        Assert::same($ref->ref, 'main');
        Assert::true(\str_contains($ref->url, 'archive.zip?sha=main'));
    }

    public function resolveArchiveUrlEncodesSpecialChars(): void
    {
        // Tag names with `/` (e.g. `release/2024`) must round-trip
        // safely through the sha query parameter.
        $http = new InMemoryHttpClient([]);
        $adapter = new GitlabAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills', ref: 'release/2024'));

        Assert::true(\str_contains($ref->url, 'sha=release%2F2024'));
    }

    // ── resolve: caret constraint ──────────────────────────────────

    public function resolveCaretPicksHighestMatchingTag(): void
    {
        $http = new InMemoryHttpClient([
            self::tagsUrl('acme%2Fskills') => self::tagsResponse([
                'v1.2.3', 'v1.5.0', 'v2.0.0', 'v0.9.0',
            ]),
        ]);
        $adapter = new GitlabAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills', ref: '^1.2.0'));

        Assert::same($ref->ref, 'v1.5.0');
    }

    public function resolveCaretNoMatchingTagThrows(): void
    {
        $http = new InMemoryHttpClient([
            self::tagsUrl('acme%2Fskills') => self::tagsResponse(['v2.0.0', 'v3.0.0']),
        ]);
        $adapter = new GitlabAdapter($http);

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('no tag in acme/skills matches');

        $adapter->resolve(self::entry('acme/skills', ref: '^1.0.0'));
    }

    // ── resolve: cascade when ref is absent ────────────────────────

    public function resolveNullRefPicksHighestStableTag(): void
    {
        $http = new InMemoryHttpClient([
            self::tagsUrl('acme%2Fskills') => self::tagsResponse([
                'v0.9.0', 'v1.2.3', 'v2.0.0-rc.1',
            ]),
        ]);
        $adapter = new GitlabAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills'));

        Assert::same($ref->ref, 'v1.2.3', 'stable beats prerelease in cascade step 1');
    }

    public function resolveNullRefFallsBackToHighestPrereleaseWhenNoStable(): void
    {
        $http = new InMemoryHttpClient([
            self::tagsUrl('acme%2Fskills') => self::tagsResponse([
                'v1.0.0-rc.1', 'v1.0.0-rc.2',
            ]),
        ]);
        $adapter = new GitlabAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills'));

        Assert::same($ref->ref, 'v1.0.0-rc.2');
    }

    public function resolveNullRefFallsBackToDefaultBranchWhenNoTags(): void
    {
        // Cascade step 3: tag listing returns []; the adapter then
        // queries /projects/{id} for default_branch.
        $http = new InMemoryHttpClient([
            self::tagsUrl('acme%2Fskills') => self::tagsResponse([]),
            'https://gitlab.com/api/v4/projects/acme%2Fskills' => self::okJson(['default_branch' => 'trunk']),
        ]);
        $adapter = new GitlabAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills'));

        Assert::same($ref->ref, 'trunk');
        Assert::true(\str_contains($ref->url, 'archive.zip?sha=trunk'));
    }

    public function resolveCascadeIgnoresNonSemverTags(): void
    {
        $http = new InMemoryHttpClient([
            self::tagsUrl('acme%2Fskills') => self::tagsResponse(['nightly', '2024-01-01']),
            'https://gitlab.com/api/v4/projects/acme%2Fskills' => self::okJson(['default_branch' => 'main']),
        ]);
        $adapter = new GitlabAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills'));

        Assert::same($ref->ref, 'main');
    }

    // ── resolve: self-hosted base URL ──────────────────────────────

    public function resolveUsesSelfHostedApiBaseWhenHostIsSet(): void
    {
        $http = new InMemoryHttpClient([
            'https://gitlab.corp.example.com/api/v4/projects/team%2Fskills/repository/tags?per_page=100' =>
                self::tagsResponse(['v1.0.0']),
        ]);
        $adapter = new GitlabAdapter($http);

        $ref = $adapter->resolve(self::entry(
            'team/skills',
            host: 'https://gitlab.corp.example.com',
        ));

        Assert::same($ref->ref, 'v1.0.0');
        Assert::true(\str_contains(
            $ref->url,
            'gitlab.corp.example.com/api/v4/projects/team%2Fskills/repository/archive.zip?sha=v1.0.0',
        ));
    }

    // ── resolve: error paths ───────────────────────────────────────

    public function resolveHttp404Throws(): void
    {
        $http = new InMemoryHttpClient([
            self::tagsUrl('acme%2Fmissing') =>
                new HttpResponse(statusCode: 404, body: '{"message":"404 Project Not Found"}'),
        ]);
        $adapter = new GitlabAdapter($http);

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('returned HTTP 404');

        $adapter->resolve(self::entry('acme/missing'));
    }

    public function resolve404AppendsAuthHintForPrivateProjects(): void
    {
        // GitLab masks a private project behind a 404, so the error must
        // point the user at Composer's GitLab auth config rather than
        // leaving them to guess. The hint names the concrete host.
        $http = new InMemoryHttpClient([
            'https://gitlab.corp.example.com/api/v4/projects/backend%2Fbe-libs%2Fskills/repository/tags?per_page=100' =>
                new HttpResponse(statusCode: 404, body: '{"message":"404 Project Not Found"}'),
        ]);
        $adapter = new GitlabAdapter($http);

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('gitlab-token.gitlab.corp.example.com');

        $adapter->resolve(self::entry('backend/be-libs/skills', host: 'https://gitlab.corp.example.com'));
    }

    public function transport404AlsoAppendsAuthHint(): void
    {
        // Composer's HttpDownloader raises on 4xx, so the 404 arrives as
        // a transport exception, not a 404 response — the hint must fire
        // on that path too.
        $url = self::tagsUrl('acme%2Fsecret');
        $http = new InMemoryHttpClient([
            $url => new HttpException(
                $url,
                'The "' . $url . '" file could not be downloaded (HTTP/2 404 ): {"message":"404 Project Not Found"}',
            ),
        ]);
        $adapter = new GitlabAdapter($http);

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('gitlab-domains gitlab.com');

        $adapter->resolve(self::entry('acme/secret'));
    }

    public function resolveTransportErrorWraps(): void
    {
        $http = new InMemoryHttpClient([
            self::tagsUrl('acme%2Fskills') =>
                new HttpException(self::tagsUrl('acme%2Fskills'), 'connection refused'),
        ]);
        $adapter = new GitlabAdapter($http);

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('connection refused');

        $adapter->resolve(self::entry('acme/skills'));
    }

    public function resolveInvalidJsonThrows(): void
    {
        $http = new InMemoryHttpClient([
            self::tagsUrl('acme%2Fskills') =>
                new HttpResponse(statusCode: 200, body: '{not json'),
        ]);
        $adapter = new GitlabAdapter($http);

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('invalid JSON');

        $adapter->resolve(self::entry('acme/skills'));
    }

    public function resolveRejectsEntryFromOtherAdapter(): void
    {
        $adapter = self::adapter();

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('adapter id mismatch');

        $adapter->resolve(new RemoteEntry(
            from: 'github',
            package: 'acme/x',
            url: null,
            host: null,
            ref: null,
        ));
    }

    // ── helpers ────────────────────────────────────────────────────

    private static function adapter(): GitlabAdapter
    {
        return new GitlabAdapter(new InMemoryHttpClient([]));
    }

    /**
     * @param non-empty-string $package
     * @param non-empty-string|null $host
     * @param non-empty-string|null $ref
     */
    private static function entry(string $package, ?string $host = null, ?string $ref = null): RemoteEntry
    {
        return new RemoteEntry(
            from: 'gitlab',
            package: $package,
            url: null,
            host: $host,
            ref: $ref,
        );
    }

    /**
     * @param non-empty-string $encodedProjectId already-URL-encoded id, e.g. `acme%2Fskills`
     *
     * @return non-empty-string
     */
    private static function tagsUrl(string $encodedProjectId): string
    {
        return 'https://gitlab.com/api/v4/projects/' . $encodedProjectId . '/repository/tags?per_page=100';
    }

    /**
     * @param list<string> $tags
     */
    private static function tagsResponse(array $tags): HttpResponse
    {
        $body = \json_encode(\array_map(static fn(string $t) => ['name' => $t], $tags), \JSON_THROW_ON_ERROR);
        return new HttpResponse(statusCode: 200, body: $body);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function okJson(array $data): HttpResponse
    {
        return new HttpResponse(
            statusCode: 200,
            body: \json_encode($data, \JSON_THROW_ON_ERROR),
        );
    }
}
