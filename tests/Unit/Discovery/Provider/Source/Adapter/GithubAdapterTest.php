<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider\Source\Adapter;

use LLM\Skills\Config\SourceEntry;
use LLM\Skills\Discovery\Provider\Source\Adapter\GithubAdapter;
use LLM\Skills\Discovery\Provider\Source\Adapter\ParsedAddInput;
use LLM\Skills\Discovery\Provider\Source\Adapter\RemoteResolveException;
use LLM\Skills\Discovery\Provider\Source\Http\HttpException;
use LLM\Skills\Discovery\Provider\Source\Http\HttpResponse;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

/**
 * Unit coverage for {@see GithubAdapter}. Uses a stub
 * {@see HttpClient} that returns canned responses keyed by URL — no
 * real HTTP traffic. Verifies both the parsing branch
 * ({@see GithubAdapter::parseAddInput()}, no IO at all) and the
 * resolve branch ({@see GithubAdapter::resolve()}, which makes
 * up to two API calls per entry).
 */
#[Test]
#[Covers(GithubAdapter::class)]
#[Covers(ParsedAddInput::class)]
#[Covers(RemoteResolveException::class)]
final class GithubAdapterTest
{
    // ── parseAddInput: shorthand and URLs ──────────────────────────

    public function shorthandOwnerSlashRepoIsParsed(): void
    {
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('acme/skills');

        Assert::same($parsed->from, 'github');
        Assert::same($parsed->package, 'acme/skills');
        Assert::same($parsed->host, null);
        Assert::same($parsed->ref, null);
        Assert::same($parsed->url, null);
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
        // `--ref` is allowed to win when it equals the embedded ref;
        // the two only conflict when they disagree.
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

    public function fullGithubUrlIsParsed(): void
    {
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('https://github.com/acme/skills');

        Assert::same($parsed->package, 'acme/skills');
        Assert::same($parsed->host, null, 'github.com is left implicit');
    }

    public function fullGheUrlIsParsedWithExplicitHost(): void
    {
        // GHE URLs MUST keep the host explicit so the adapter can build
        // the right /api/v3 base later. github.com is the only one that
        // gets implicit treatment.
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('https://github.corp.example.com/team/skills');

        Assert::same($parsed->package, 'team/skills');
        Assert::same($parsed->host, 'https://github.corp.example.com');
    }

    public function gitSuffixIsStripped(): void
    {
        $adapter = self::adapter();

        $parsed = $adapter->parseAddInput('https://github.com/acme/skills.git');

        Assert::same($parsed->package, 'acme/skills');
    }

    public function hostOverrideConflictingWithUrlHostThrows(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('conflicts with URL host');

        self::adapter()->parseAddInput(
            'https://github.com/acme/skills',
            hostOverride: 'https://github.corp.example.com',
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
            ->withMessageContaining('owner/repo');

        self::adapter()->parseAddInput('flat-name');
    }

    public function shorthandWithExtraSlashThrows(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('owner/repo');

        self::adapter()->parseAddInput('a/b/c');
    }

    // ── apiBaseFor: github.com vs GHE ──────────────────────────────

    public function apiBaseForGithubComUsesDefault(): void
    {
        $adapter = self::adapter();

        Assert::same($adapter->apiBaseFor(null), 'https://api.github.com');
        Assert::same($adapter->apiBaseFor('https://github.com'), 'https://api.github.com');
    }

    public function apiBaseForGheAppendsApiV3(): void
    {
        $adapter = self::adapter();

        Assert::same(
            $adapter->apiBaseFor('https://github.corp.example.com'),
            'https://github.corp.example.com/api/v3',
        );
        // Trailing slash on stored host gets normalised away — both
        // shapes produce the same API base, so swapping one for the
        // other isn't a silent breakage.
        Assert::same(
            $adapter->apiBaseFor('https://github.corp.example.com/'),
            'https://github.corp.example.com/api/v3',
        );
    }

    // ── resolve: explicit ref ──────────────────────────────────────

    public function resolveExplicitTagSkipsApiCalls(): void
    {
        // A literal tag (not a constraint) doesn't need a tag listing
        // — the adapter just hits zipball/<tag> directly. The stub
        // would throw on any GET, so reaching the ref unchanged proves
        // no API was called.
        $http = new InMemoryHttpClient([]);
        $adapter = new GithubAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills', ref: 'v1.2.3'));

        Assert::same($ref->ref, 'v1.2.3');
        Assert::true(\str_ends_with($ref->url, '/repos/acme/skills/zipball/v1.2.3'));
        Assert::same($http->callCount(), 0);
    }

    public function resolveBranchRefIsTreatedAsLiteral(): void
    {
        $http = new InMemoryHttpClient([]);
        $adapter = new GithubAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills', ref: 'main'));

        Assert::same($ref->ref, 'main');
        Assert::true(\str_contains($ref->url, '/zipball/main'));
    }

    public function resolveZipballUrlEncodesSpecialChars(): void
    {
        // Tag names with `/` (e.g. `release/2024.01`) must round-trip
        // safely through the URL path segment. rawurlencode handles
        // this.
        $http = new InMemoryHttpClient([]);
        $adapter = new GithubAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills', ref: 'release/2024'));

        Assert::true(\str_contains($ref->url, '/zipball/release%2F2024'));
    }

    // ── resolve: caret constraint ──────────────────────────────────

    public function resolveCaretPicksHighestMatchingTag(): void
    {
        $http = new InMemoryHttpClient([
            'https://api.github.com/repos/acme/skills/tags?per_page=100' => self::tagsResponse([
                'v1.2.3', 'v1.5.0', 'v2.0.0', 'v0.9.0',
            ]),
        ]);
        $adapter = new GithubAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills', ref: '^1.2.0'));

        Assert::same($ref->ref, 'v1.5.0');
    }

    public function resolveCaretResolvesPre1Constraint(): void
    {
        // Regression: `skills:add owner/repo` on a 0.x donor stores a
        // `^0.y.z` caret (via RefResolver::formatCaret), and the
        // follow-up sync must be able to resolve it — otherwise the
        // just-registered skill never lands in the target.
        $http = new InMemoryHttpClient([
            'https://api.github.com/repos/acme/skills/tags?per_page=100' => self::tagsResponse([
                '0.10.37', '0.10.38', '0.11.0',
            ]),
        ]);
        $adapter = new GithubAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills', ref: '^0.10.38'));

        // `^0.10.38` locks the minor → `0.11.0` is out of range.
        Assert::same($ref->ref, '0.10.38');
    }

    public function resolveCaretNoMatchingTagThrows(): void
    {
        $http = new InMemoryHttpClient([
            'https://api.github.com/repos/acme/skills/tags?per_page=100' => self::tagsResponse(['v2.0.0', 'v3.0.0']),
        ]);
        $adapter = new GithubAdapter($http);

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('no tag in acme/skills matches');

        $adapter->resolve(self::entry('acme/skills', ref: '^1.0.0'));
    }

    // ── resolve: cascade when ref is absent ────────────────────────

    public function resolveNullRefPicksHighestStableTag(): void
    {
        $http = new InMemoryHttpClient([
            'https://api.github.com/repos/acme/skills/tags?per_page=100' => self::tagsResponse([
                'v0.9.0', 'v1.2.3', 'v2.0.0-rc.1',
            ]),
        ]);
        $adapter = new GithubAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills'));

        Assert::same($ref->ref, 'v1.2.3', 'stable beats prerelease in cascade step 1');
    }

    public function resolveNullRefFallsBackToHighestPrereleaseWhenNoStable(): void
    {
        $http = new InMemoryHttpClient([
            'https://api.github.com/repos/acme/skills/tags?per_page=100' => self::tagsResponse([
                'v1.0.0-rc.1', 'v1.0.0-rc.2',
            ]),
        ]);
        $adapter = new GithubAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills'));

        Assert::same($ref->ref, 'v1.0.0-rc.2');
    }

    public function resolveNullRefFallsBackToDefaultBranchWhenNoTags(): void
    {
        // Cascade step 3: tag listing returns []; the adapter then
        // queries /repos/{owner}/{repo} for default_branch.
        $http = new InMemoryHttpClient([
            'https://api.github.com/repos/acme/skills/tags?per_page=100' => self::tagsResponse([]),
            'https://api.github.com/repos/acme/skills' => self::okJson(['default_branch' => 'trunk']),
        ]);
        $adapter = new GithubAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills'));

        Assert::same($ref->ref, 'trunk');
        Assert::true(\str_contains($ref->url, '/zipball/trunk'));
    }

    public function resolveCascadeIgnoresNonSemverTags(): void
    {
        // Tags like `nightly` / `latest` / `2024-01-01` are not
        // semver-shape and must be invisible to the cascade — the
        // adapter falls through to default branch.
        $http = new InMemoryHttpClient([
            'https://api.github.com/repos/acme/skills/tags?per_page=100' => self::tagsResponse(['nightly', '2024-01-01']),
            'https://api.github.com/repos/acme/skills' => self::okJson(['default_branch' => 'main']),
        ]);
        $adapter = new GithubAdapter($http);

        $ref = $adapter->resolve(self::entry('acme/skills'));

        Assert::same($ref->ref, 'main');
    }

    // ── resolve: GHE base URL ──────────────────────────────────────

    public function resolveUsesGheApiBaseWhenHostIsSet(): void
    {
        $http = new InMemoryHttpClient([
            'https://github.corp.example.com/api/v3/repos/team/skills/tags?per_page=100' =>
                self::tagsResponse(['v1.0.0']),
        ]);
        $adapter = new GithubAdapter($http);

        $ref = $adapter->resolve(self::entry(
            'team/skills',
            host: 'https://github.corp.example.com',
        ));

        Assert::same($ref->ref, 'v1.0.0');
        Assert::true(\str_contains(
            $ref->url,
            'github.corp.example.com/api/v3/repos/team/skills/zipball/v1.0.0',
        ));
    }

    // ── resolve: error paths ───────────────────────────────────────

    public function resolveHttp404Throws(): void
    {
        $http = new InMemoryHttpClient([
            'https://api.github.com/repos/acme/missing/tags?per_page=100' =>
                new HttpResponse(statusCode: 404, body: '{"message":"Not Found"}'),
        ]);
        $adapter = new GithubAdapter($http);

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('returned HTTP 404');

        $adapter->resolve(self::entry('acme/missing'));
    }

    public function resolveTransportErrorWraps(): void
    {
        $http = new InMemoryHttpClient([
            'https://api.github.com/repos/acme/skills/tags?per_page=100' =>
                new HttpException('https://api.github.com/repos/acme/skills/tags?per_page=100', 'connection refused'),
        ]);
        $adapter = new GithubAdapter($http);

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('connection refused');

        $adapter->resolve(self::entry('acme/skills'));
    }

    public function resolveInvalidJsonThrows(): void
    {
        $http = new InMemoryHttpClient([
            'https://api.github.com/repos/acme/skills/tags?per_page=100' =>
                new HttpResponse(statusCode: 200, body: '{not json'),
        ]);
        $adapter = new GithubAdapter($http);

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('invalid JSON');

        $adapter->resolve(self::entry('acme/skills'));
    }

    public function resolveRejectsEntryFromOtherAdapter(): void
    {
        $adapter = self::adapter();

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('adapter id mismatch');

        $adapter->resolve(new SourceEntry(
            from: 'gitlab',
            package: 'acme/x',
            url: null,
            host: null,
            ref: null,
        ));
    }

    public function SourceEntryConstructorRejectsBothPackageAndUrlNull(): void
    {
        // The "package required for github" check used to live in
        // GithubAdapter::resolve(), but SourceEntry's constructor now
        // refuses entries where neither `package` nor `url` is set —
        // a malformed github entry can no longer reach the adapter.
        // Keep the test as the lower-tier invariant probe so the
        // VO's contract stays covered here.
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('neither set');

        new SourceEntry(
            from: 'github',
            package: null,
            url: null,
            host: null,
            ref: null,
        );
    }

    // ── resolve: cooldown ──────────────────────────────────────────

    public function cooldownTagWhoseCommitWasNotOnTheDefaultBranchIsSkipped(): void
    {
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse(['v1.0.0', 'v1.1.0']),
            ...self::defaultBranchAtCutoff(),
            self::compareUrl('refs/tags/v1.1.0') => self::compareResponse('diverged', \sha1('v1.1.0')),
            self::compareUrl('refs/tags/v1.0.0') => self::compareResponse('ahead', \sha1('v1.0.0')),
        ]);

        $ref = (new GithubAdapter($http))->resolve(self::entry('acme/skills'), self::cutoff());

        Assert::same($ref->ref, 'v1.0.0');
        Assert::same($ref->url, 'https://api.github.com/repos/acme/skills/zipball/' . \sha1('v1.0.0'));
        Assert::same($http->callCount(), 5);
    }

    public function cooldownTagAtTheDefaultBranchStateIsPicked(): void
    {
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse(['v1.0.0', 'v1.1.0']),
            ...self::defaultBranchAtCutoff(),
            self::compareUrl('refs/tags/v1.1.0') => self::compareResponse('identical', \sha1('v1.1.0')),
        ]);

        $ref = (new GithubAdapter($http))->resolve(self::entry('acme/skills'), self::cutoff());

        Assert::same($ref->ref, 'v1.1.0');
    }

    public function cooldownFailsWhenNoTagIsOldEnough(): void
    {
        // No stub for the default branch's head: a fallback would hit an unstubbed URL.
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse(['v1.0.0']),
            ...self::defaultBranchAtCutoff(),
            self::compareUrl('refs/tags/v1.0.0') => self::compareResponse('diverged', \sha1('v1.0.0')),
        ]);

        Expect::exception(RemoteResolveException::class)->withMessageContaining(
            'cooldown: no tag was on the default branch at 2026-11-01 00:00 UTC',
        );

        (new GithubAdapter($http))->resolve(self::entry('acme/skills'), self::cutoff());
    }

    public function cooldownStableTierDoesNotFallBackToPrerelease(): void
    {
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse(['v1.0.0', 'v1.1.0-rc.1']),
            ...self::defaultBranchAtCutoff(),
            self::compareUrl('refs/tags/v1.0.0') => self::compareResponse('diverged', \sha1('v1.0.0')),
        ]);

        Expect::exception(RemoteResolveException::class)->withMessageContaining('cooldown: no tag was on');

        (new GithubAdapter($http))->resolve(self::entry('acme/skills'), self::cutoff());
    }

    public function cooldownConstraintPicksHighestMatchingTagOldEnough(): void
    {
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse(['v1.0.0', 'v1.5.0', 'v2.0.0']),
            ...self::defaultBranchAtCutoff(),
            self::compareUrl('refs/tags/v1.5.0') => self::compareResponse('diverged', \sha1('v1.5.0')),
            self::compareUrl('refs/tags/v1.0.0') => self::compareResponse('ahead', \sha1('v1.0.0')),
        ]);

        $ref = (new GithubAdapter($http))->resolve(self::entry('acme/skills', ref: '^1.0'), self::cutoff());

        Assert::same($ref->ref, 'v1.0.0');
        Assert::same($ref->url, 'https://api.github.com/repos/acme/skills/zipball/' . \sha1('v1.0.0'));
    }

    public function cooldownConstraintFailsWhenNoMatchingTagIsOldEnough(): void
    {
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse(['v1.5.0', 'v2.0.0']),
            ...self::defaultBranchAtCutoff(),
            self::compareUrl('refs/tags/v1.5.0') => self::compareResponse('diverged', \sha1('v1.5.0')),
        ]);

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('cooldown: no tag matching ^1.0 was on the default branch at');

        (new GithubAdapter($http))->resolve(self::entry('acme/skills', ref: '^1.0'), self::cutoff());
    }

    public function cooldownCascadePicksPrereleaseWhenTheRepositoryHasNoStableTag(): void
    {
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse(['v1.0.0-rc.1', 'v1.0.0-rc.2']),
            ...self::defaultBranchAtCutoff(),
            self::compareUrl('refs/tags/v1.0.0-rc.2') => self::compareResponse('diverged', \sha1('v1.0.0-rc.2')),
            self::compareUrl('refs/tags/v1.0.0-rc.1') => self::compareResponse('ahead', \sha1('v1.0.0-rc.1')),
        ]);

        $ref = (new GithubAdapter($http))->resolve(self::entry('acme/skills'), self::cutoff());

        Assert::same($ref->ref, 'v1.0.0-rc.1');
    }

    public function cooldownChecksAtMostTenCandidates(): void
    {
        $tags = [];
        $responses = self::defaultBranchAtCutoff();
        for ($minor = 0; $minor <= 10; ++$minor) {
            $tags[] = 'v1.' . $minor . '.0';
            if ($minor > 0) {
                $responses[self::compareUrl('refs/tags/v1.' . $minor . '.0')] = self::compareResponse('diverged');
            }
        }
        $responses[self::TAGS_URL] = self::tagsResponse($tags);

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('was on the default branch at 2026-11-01 00:00 UTC (checked the 10 highest candidates)');

        (new GithubAdapter(new InMemoryHttpClient($responses)))->resolve(self::entry('acme/skills'), self::cutoff());
    }

    public function cooldownExactConstraintIsChecked(): void
    {
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse(['v1.0.0', 'v1.5.0']),
            ...self::defaultBranchAtCutoff(),
            self::compareUrl('refs/tags/v1.5.0') => self::compareResponse('diverged'),
        ]);

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('cooldown: no tag matching =1.5.0 was on the default branch at 2026-11-01 00:00 UTC');

        (new GithubAdapter($http))->resolve(self::entry('acme/skills', ref: '=1.5.0'), self::cutoff());
    }

    public function cooldownBranchTakesAPushExactlyAtTheCutoff(): void
    {
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse([]),
            'https://api.github.com/repos/acme/skills' => self::okJson(['default_branch' => 'main']),
            self::activityUrl('main') => self::activityResponse([
                ['2026-11-01T00:00:01Z', self::sha('a')],
                ['2026-11-01T00:00:00Z', self::sha('b')],
            ]),
        ]);

        $ref = (new GithubAdapter($http))->resolve(self::entry('acme/skills'), self::cutoff());

        Assert::same($ref->ref, self::sha('b'));
    }

    public function cooldownBranchResolvesToCommitAtCutoff(): void
    {
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse([]),
            'https://api.github.com/repos/acme/skills' => self::okJson(['default_branch' => 'main']),
            self::activityUrl('main') => self::activityResponse([
                ['2026-11-05T10:00:00Z', self::sha('a')],
                ['2026-10-30T10:00:00Z', self::sha('b')],
                ['2026-10-01T10:00:00Z', self::sha('c')],
            ]),
        ]);

        $ref = (new GithubAdapter($http))->resolve(self::entry('acme/skills'), self::cutoff());

        Assert::same($ref->ref, self::sha('b'));
        Assert::same($ref->url, 'https://api.github.com/repos/acme/skills/zipball/' . self::sha('b'));
    }

    public function cooldownBranchFollowsNextActivityPage(): void
    {
        $page2 = 'https://api.github.com/repositories/1/activity?ref=refs%2Fheads%2Fmain&after=cursor';
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse([]),
            'https://api.github.com/repos/acme/skills' => self::okJson(['default_branch' => 'main']),
            self::activityUrl('main') => self::activityResponse([['2026-11-05T10:00:00Z', self::sha('a')]], next: $page2),
            $page2 => self::activityResponse([['2026-10-30T10:00:00Z', self::sha('b')]]),
        ]);

        $ref = (new GithubAdapter($http))->resolve(self::entry('acme/skills'), self::cutoff());

        Assert::same($ref->ref, self::sha('b'));
    }

    public function cooldownBranchDeletedAtCutoffFails(): void
    {
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse([]),
            'https://api.github.com/repos/acme/skills' => self::okJson(['default_branch' => 'main']),
            self::activityUrl('main') => self::activityResponse([
                ['2026-11-05T10:00:00Z', self::sha('a')],
                ['2026-10-30T10:00:00Z', self::sha('0')],
                ['2026-10-01T10:00:00Z', self::sha('c')],
            ]),
        ]);

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('cooldown: branch "main" did not exist at 2026-11-01 00:00 UTC');

        (new GithubAdapter($http))->resolve(self::entry('acme/skills'), self::cutoff());
    }

    public function cooldownBranchOlderThanTheLogResolvesToTheStateBeforeItsOldestEvent(): void
    {
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse([]),
            'https://api.github.com/repos/acme/skills' => self::okJson(['default_branch' => 'main']),
            self::activityUrl('main') => self::activityResponse([
                ['2026-11-05T10:00:00Z', self::sha('a'), self::sha('b')],
                ['2026-11-03T10:00:00Z', self::sha('b'), self::sha('c')],
            ]),
        ]);

        $ref = (new GithubAdapter($http))->resolve(self::entry('acme/skills'), self::cutoff());

        Assert::same($ref->ref, self::sha('c'));
    }

    public function cooldownBranchCreatedAfterTheCutoffFails(): void
    {
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse([]),
            'https://api.github.com/repos/acme/skills' => self::okJson(['default_branch' => 'main']),
            self::activityUrl('main') => self::activityResponse([
                ['2026-11-05T10:00:00Z', self::sha('a'), self::sha('0')],
            ]),
        ]);

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('cooldown: branch "main" did not exist at 2026-11-01 00:00 UTC');

        (new GithubAdapter($http))->resolve(self::entry('acme/skills'), self::cutoff());
    }

    public function cooldownBranchWithoutRecordedActivityResolvesToItsHead(): void
    {
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse([]),
            'https://api.github.com/repos/acme/skills' => self::okJson(['default_branch' => 'main']),
            self::activityUrl('main') => self::activityResponse([]),
            'https://api.github.com/repos/acme/skills/git/matching-refs/heads/main' => self::refsResponse([
                'refs/heads/main' => self::sha('d'),
            ]),
        ]);

        $ref = (new GithubAdapter($http))->resolve(self::entry('acme/skills'), self::cutoff());

        Assert::same($ref->ref, self::sha('d'));
    }

    public function cooldownMissingBranchFails(): void
    {
        $http = new InMemoryHttpClient([
            self::TAGS_URL => self::tagsResponse([]),
            'https://api.github.com/repos/acme/skills' => self::okJson(['default_branch' => 'main']),
            self::activityUrl('main') => self::activityResponse([]),
            'https://api.github.com/repos/acme/skills/git/matching-refs/heads/main' => self::refsResponse([]),
        ]);

        Expect::exception(RemoteResolveException::class)->withMessageContaining('branch "main" does not exist');

        (new GithubAdapter($http))->resolve(self::entry('acme/skills'), self::cutoff());
    }

    public function cooldownBranchHistoryIsReadUpToTenPages(): void
    {
        $responses = [
            self::TAGS_URL => self::tagsResponse([]),
            'https://api.github.com/repos/acme/skills' => self::okJson(['default_branch' => 'main']),
        ];
        $url = self::activityUrl('main');
        for ($page = 1; $page <= 10; ++$page) {
            $next = 'https://api.github.com/repositories/1/activity?page=' . ($page + 1);
            $responses[$url] = self::activityResponse([['2026-11-05T10:00:00Z', self::sha('a')]], next: $next);
            $url = $next;
        }

        Expect::exception(RemoteResolveException::class)
            ->withMessageContaining('cooldown: the push history of branch "main" since 2026-11-01 00:00 UTC is longer than 10 pages');

        (new GithubAdapter(new InMemoryHttpClient($responses)))->resolve(self::entry('acme/skills'), self::cutoff());
    }

    public function cooldownVerbatimBranchIsDated(): void
    {
        $http = new InMemoryHttpClient([
            'https://api.github.com/repos/acme/skills/git/matching-refs/heads/release%2F1.x' => self::refsResponse([
                'refs/heads/release/1.x' => self::sha('e'),
                'refs/heads/release/1.x-old' => self::sha('f'),
            ]),
            self::activityUrl('release/1.x') => self::activityResponse([['2026-10-30T10:00:00Z', self::sha('b')]]),
        ]);

        $ref = (new GithubAdapter($http))->resolve(self::entry('acme/skills', ref: 'release/1.x'), self::cutoff());

        Assert::same($ref->ref, self::sha('b'));
    }

    public function cooldownBranchWinsOverATagOfTheSameName(): void
    {
        $http = new InMemoryHttpClient([
            'https://api.github.com/repos/acme/skills/git/matching-refs/heads/main' => self::refsResponse([
                'refs/heads/main' => self::sha('a'),
            ]),
            self::activityUrl('main') => self::activityResponse([['2026-10-30T10:00:00Z', self::sha('b')]]),
        ]);

        $ref = (new GithubAdapter($http))->resolve(self::entry('acme/skills', ref: 'main'), self::cutoff());

        Assert::same($ref->ref, self::sha('b'));
    }

    #[DataSet(['v1.2.3'], name: 'tag')]
    #[DataSet(['refs/heads/main'], name: 'full branch ref')]
    #[DataSet(['HEAD'], name: 'HEAD')]
    #[DataSet(['abc1234'], name: 'short SHA')]
    #[DataSet(['eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'], name: 'full SHA')]
    public function cooldownRefOtherThanABranchNameIsFetchedAtItsCommitWhenThatWasOnTheDefaultBranch(string $verbatim): void
    {
        $http = new InMemoryHttpClient([
            ...self::noBranchNamed($verbatim),
            ...self::defaultBranchAtCutoff(),
            self::compareUrl($verbatim) => self::compareResponse('ahead', self::sha('e')),
        ]);

        $ref = (new GithubAdapter($http))->resolve(self::entry('acme/skills', ref: $verbatim), self::cutoff());

        Assert::same($ref->ref, $verbatim);
        Assert::same($ref->url, 'https://api.github.com/repos/acme/skills/zipball/' . self::sha('e'));
    }

    #[DataSet(['v1.2.3', 'diverged'], name: 'moved tag')]
    #[DataSet(['refs/pull/1/head', 'behind'], name: 'pull request head')]
    #[DataSet(['HEAD', 'behind'], name: 'HEAD moved since the cutoff')]
    public function cooldownRefOtherThanABranchNameFailsWhenItsCommitWasNotOnTheDefaultBranch(string $verbatim, string $status): void
    {
        $http = new InMemoryHttpClient([
            ...self::noBranchNamed($verbatim),
            ...self::defaultBranchAtCutoff(),
            self::compareUrl($verbatim) => self::compareResponse($status),
        ]);

        Expect::exception(RemoteResolveException::class)->withMessageContaining(
            'cooldown: "' . $verbatim . '" was not on the default branch at 2026-11-01 00:00 UTC',
        );

        (new GithubAdapter($http))->resolve(self::entry('acme/skills', ref: $verbatim), self::cutoff());
    }

    // ── helpers ────────────────────────────────────────────────────

    private const TAGS_URL = 'https://api.github.com/repos/acme/skills/tags?per_page=100';
    private const REPO_URL = 'https://api.github.com/repos/acme/skills';

    private static function cutoff(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-01T00:00:00Z');
    }

    /**
     * @return non-empty-string
     */
    private static function sha(string $digit): string
    {
        return \str_repeat($digit, 40);
    }

    private static function activityUrl(string $branch): string
    {
        return 'https://api.github.com/repos/acme/skills/activity?ref='
            . \rawurlencode('refs/heads/' . $branch) . '&direction=desc&per_page=100';
    }

    /**
     * @param list<array{0: string, 1: string, 2?: string}> $events `[timestamp, after, before]`, newest first
     */
    private static function activityResponse(array $events, ?string $next = null): HttpResponse
    {
        return self::okJsonList(
            \array_map(
                static fn(array $event): array => [
                    'timestamp' => $event[0],
                    'after' => $event[1],
                    'before' => $event[2] ?? self::sha('9'),
                    'activity_type' => 'push',
                ],
                $events,
            ),
            $next,
        );
    }

    /**
     * @param array<string, string> $refs ref name => commit SHA
     */
    private static function refsResponse(array $refs): HttpResponse
    {
        $items = [];
        foreach ($refs as $ref => $sha) {
            $items[] = ['ref' => $ref, 'object' => ['sha' => $sha, 'type' => 'commit']];
        }
        return self::okJsonList($items);
    }

    /**
     * The default branch `main` pointed at `sha('b')` at the cutoff.
     *
     * @return array<string, HttpResponse>
     */
    private static function defaultBranchAtCutoff(): array
    {
        return [
            self::REPO_URL => self::okJson(['default_branch' => 'main']),
            self::activityUrl('main') => self::activityResponse([
                ['2026-11-05T10:00:00Z', self::sha('a'), self::sha('b')],
                ['2026-10-30T10:00:00Z', self::sha('b')],
            ]),
        ];
    }

    /**
     * Compares `$ref` with the default branch at the cutoff, see {@see defaultBranchAtCutoff()}.
     */
    private static function compareUrl(string $ref): string
    {
        return self::REPO_URL . '/compare/' . \rawurlencode($ref) . '...' . self::sha('b') . '?per_page=1';
    }

    private static function compareResponse(string $status, ?string $base = null): HttpResponse
    {
        return self::okJson(['status' => $status, 'base_commit' => ['sha' => $base ?? self::sha('e')]]);
    }

    /**
     * @return array<string, HttpResponse>
     */
    private static function noBranchNamed(string $ref): array
    {
        if (\preg_match('/^[0-9a-f]{40}$/', $ref) === 1) {
            return [];
        }
        return [self::REPO_URL . '/git/matching-refs/heads/' . \rawurlencode($ref) => self::refsResponse([])];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private static function okJsonList(array $items, ?string $next = null): HttpResponse
    {
        return new HttpResponse(
            statusCode: 200,
            body: \json_encode($items, \JSON_THROW_ON_ERROR),
            headers: $next === null ? [] : ['link' => '<' . $next . '>; rel="next", <' . $next . '&last>; rel="last"'],
        );
    }

    private static function adapter(): GithubAdapter
    {
        return new GithubAdapter(new InMemoryHttpClient([]));
    }

    /**
     * @param non-empty-string $package
     * @param non-empty-string|null $host
     * @param non-empty-string|null $ref
     */
    private static function entry(string $package, ?string $host = null, ?string $ref = null): SourceEntry
    {
        return new SourceEntry(
            from: 'github',
            package: $package,
            url: null,
            host: $host,
            ref: $ref,
        );
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
