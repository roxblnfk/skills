<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider\Remote;

use Internal\Path;
use LLM\Skills\Discovery\Provider\Remote\NullRemoteDonorSource;
use LLM\Skills\Discovery\Provider\Remote\RemoteDonorRef;
use LLM\Skills\Discovery\Provider\Remote\RemoteDonorSource;
use LLM\Skills\Discovery\Provider\Remote\RemoteFetchException;
use LLM\Skills\Discovery\Provider\Remote\RemoteFetcher;
use LLM\Skills\Discovery\Provider\Remote\RemoteProvider;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Unit coverage for {@see RemoteProvider}.
 *
 * Each test wires a small test-double {@see RemoteDonorSource} +
 * {@see RemoteFetcher} pair and verifies how the provider handles
 * the resulting (success / failure) signal. The fetcher's "extract
 * to disk" is faked by writing a `composer.json` into a temp dir
 * and returning its path — the provider is filesystem-aware and
 * the test must exercise that boundary, but no real network IO
 * happens.
 */
#[Test]
#[Covers(RemoteProvider::class)]
#[Covers(NullRemoteDonorSource::class)]
#[Covers(RemoteDonorRef::class)]
#[Covers(RemoteFetchException::class)]
final class RemoteProviderTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-remote-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function inactiveWhenSourceEmpty(): void
    {
        $provider = new RemoteProvider(new NullRemoteDonorSource());

        Assert::false($provider->isActive($this->projectRoot()));
        $result = $provider->discover($this->projectRoot());
        Assert::same($result->donors, []);
        Assert::same($result->warnings, []);
    }

    public function activeWhenSourceYieldsAtLeastOneRef(): void
    {
        $provider = new RemoteProvider(
            $this->sourceWithRefs($this->ref('https://example.com/repo.git', 'main')),
        );

        Assert::true($provider->isActive($this->projectRoot()));
    }

    public function warnsOnceWhenSourceActiveButFetcherMissing(): void
    {
        // Misconfiguration is global, not per-ref — one warning is
        // more useful than N copies of the same message.
        $provider = new RemoteProvider(
            $this->sourceWithRefs(
                $this->ref('https://example.com/a.git', 'main'),
                $this->ref('https://example.com/b.git', 'main'),
            ),
        );

        $result = $provider->discover($this->projectRoot());

        Assert::same($result->donors, []);
        Assert::count($result->warnings, 1);
        Assert::true(\str_contains($result->warnings[0], 'no fetcher is configured'));
    }

    public function warnsOnFetchException(): void
    {
        $ref = $this->ref('https://example.com/repo.git', 'v1.0.0');
        $fetcher = new class($ref) implements RemoteFetcher {
            public function __construct(private readonly RemoteDonorRef $expected) {}

            public function fetch(RemoteDonorRef $ref): Path
            {
                throw new RemoteFetchException($this->expected, 'host unreachable');
            }
        };

        $provider = new RemoteProvider($this->sourceWithRefs($ref), $fetcher);
        $result = $provider->discover($this->projectRoot());

        Assert::same($result->donors, []);
        Assert::count($result->warnings, 1);
        Assert::true(\str_contains($result->warnings[0], 'host unreachable'));
        Assert::true(\str_contains($result->warnings[0], $ref->describe()));
    }

    public function warnsWhenComposerJsonMissing(): void
    {
        $extracted = $this->makeExtracted('archive-without-composer-json');
        // ...intentionally no composer.json inside

        $provider = $this->providerReturning($extracted);
        $result = $provider->discover($this->projectRoot());

        Assert::same($result->donors, []);
        Assert::count($result->warnings, 1);
        Assert::true(\str_contains($result->warnings[0], 'composer.json missing'));
    }

    public function warnsWhenComposerJsonInvalidJson(): void
    {
        $extracted = $this->makeExtracted('bad-json', 'not valid json {');

        $provider = $this->providerReturning($extracted);
        $result = $provider->discover($this->projectRoot());

        Assert::same($result->donors, []);
        Assert::count($result->warnings, 1);
        Assert::true(\str_contains($result->warnings[0], 'not valid JSON'));
    }

    public function warnsWhenComposerJsonIsScalar(): void
    {
        // `42` / `"text"` decode as non-arrays. A list-root `[]` is
        // also technically invalid as a Composer manifest, but it
        // decodes as an array and gets caught one step later by the
        // missing-`name` check — equivalent rejection, different
        // warning text.
        $extracted = $this->makeExtracted('scalar-root', '42');

        $provider = $this->providerReturning($extracted);
        $result = $provider->discover($this->projectRoot());

        Assert::same($result->donors, []);
        Assert::count($result->warnings, 1);
        Assert::true(\str_contains($result->warnings[0], 'must be a JSON object'));
    }

    public function warnsWhenNameMissing(): void
    {
        $extracted = $this->makeExtracted(
            'no-name',
            \json_encode(['extra' => ['skills' => ['source' => 'skills']]]),
        );

        $provider = $this->providerReturning($extracted);
        $result = $provider->discover($this->projectRoot());

        Assert::same($result->donors, []);
        Assert::count($result->warnings, 1);
        Assert::true(\str_contains($result->warnings[0], '`name`'));
    }

    public function warnsWhenNameMissingVendorSlash(): void
    {
        $extracted = $this->makeExtracted(
            'flat-name',
            \json_encode(['name' => 'flat', 'extra' => ['skills' => ['source' => 'skills']]]),
        );

        $provider = $this->providerReturning($extracted);
        $result = $provider->discover($this->projectRoot());

        Assert::same($result->donors, []);
        Assert::count($result->warnings, 1);
        Assert::true(\str_contains($result->warnings[0], 'vendor/package'));
    }

    public function warnsWhenExtraSkillsSourceMissing(): void
    {
        // `extra.skills` exists with only root-level options — same
        // case Composer-side treats as "not a donor, skip silently".
        // For remote refs the user explicitly asked us to fetch this
        // repo, so a warning is appropriate (silent skip would mask
        // the misconfiguration).
        $extracted = $this->makeExtracted(
            'no-source',
            \json_encode([
                'name' => 'acme/pkg',
                'extra' => ['skills' => ['aliases' => ['.claude/skills']]],
            ]),
        );

        $provider = $this->providerReturning($extracted);
        $result = $provider->discover($this->projectRoot());

        Assert::same($result->donors, []);
        Assert::count($result->warnings, 1);
        Assert::true(\str_contains($result->warnings[0], 'not a donor'));
    }

    public function warnsAndRecordsMalformedWhenExtraSkillsBroken(): void
    {
        // `source` is a non-string — mapper rejects, provider lifts
        // to both a printable warning AND a structured MalformedDonor
        // entry, parity with ComposerProvider.
        $extracted = $this->makeExtracted(
            'bad-source',
            \json_encode([
                'name' => 'acme/pkg',
                'extra' => ['skills' => ['source' => 42]],
            ]),
        );

        $provider = $this->providerReturning($extracted);
        $result = $provider->discover($this->projectRoot());

        Assert::same($result->donors, []);
        Assert::count($result->warnings, 1);
        Assert::count($result->malformed, 1);
        Assert::same($result->malformed[0]->packageName, 'acme/pkg');
        Assert::true(\str_contains($result->malformed[0]->reason, 'extra.skills.source'));
    }

    public function producesDonorOnHappyPath(): void
    {
        $extracted = $this->makeExtracted(
            'happy',
            \json_encode([
                'name' => 'acme/pkg',
                'extra' => ['skills' => ['source' => 'skills']],
            ]),
        );

        $provider = $this->providerReturning($extracted);
        $result = $provider->discover($this->projectRoot());

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->packageName, 'acme/pkg');
        Assert::same($result->donors[0]->source, 'skills');
        Assert::same((string) $result->donors[0]->packageRoot, (string) $extracted);
        Assert::same($result->warnings, []);
        Assert::same($result->malformed, []);
    }

    public function oneBadRefDoesNotBlockTheRest(): void
    {
        $bad = $this->makeExtracted('bad-archive');  // no composer.json
        $good = $this->makeExtracted(
            'good-archive',
            \json_encode([
                'name' => 'acme/pkg',
                'extra' => ['skills' => ['source' => 'skills']],
            ]),
        );

        $refBad = $this->ref('https://example.com/bad.git', 'main');
        $refGood = $this->ref('https://example.com/good.git', 'main');

        $fetcher = new class($refBad, $bad, $refGood, $good) implements RemoteFetcher {
            /** @var array<string, Path> */
            private array $map;

            public function __construct(RemoteDonorRef $a, Path $aPath, RemoteDonorRef $b, Path $bPath)
            {
                $this->map = [$a->describe() => $aPath, $b->describe() => $bPath];
            }

            public function fetch(RemoteDonorRef $ref): Path
            {
                return $this->map[$ref->describe()]
                    ?? throw new RemoteFetchException($ref, 'unknown ref');
            }
        };

        $provider = new RemoteProvider($this->sourceWithRefs($refBad, $refGood), $fetcher);
        $result = $provider->discover($this->projectRoot());

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->packageName, 'acme/pkg');
        Assert::count($result->warnings, 1);
    }

    public function directDependenciesAlwaysEmpty(): void
    {
        // Even with active source + working fetcher: remote refs are
        // never "direct deps" in the implicit-trust sense. Trust for
        // remote donors flows exclusively through `trusted` patterns.
        $extracted = $this->makeExtracted(
            'happy',
            \json_encode([
                'name' => 'acme/pkg',
                'extra' => ['skills' => ['source' => 'skills']],
            ]),
        );

        $provider = $this->providerReturning($extracted);

        Assert::same($provider->directDependencies($this->projectRoot()), []);
    }

    private function projectRoot(): Path
    {
        return Path::create($this->tmp . '/project');
    }

    /**
     * @param non-empty-string $url
     * @param non-empty-string $ref
     */
    private function ref(string $url, string $ref): RemoteDonorRef
    {
        return new RemoteDonorRef($url, $ref);
    }

    private function sourceWithRefs(RemoteDonorRef ...$refs): RemoteDonorSource
    {
        return new class($refs) implements RemoteDonorSource {
            /** @param list<RemoteDonorRef> $refs */
            public function __construct(private readonly array $refs) {}

            public function refs(Path $projectRoot): iterable
            {
                return $this->refs;
            }
        };
    }

    /**
     * Write `$contents` (or skip the file if `null`) into a fresh
     * subdirectory and return its Path — the same shape a real
     * fetcher would return after extracting an archive.
     */
    private function makeExtracted(string $label, ?string $contents = null): Path
    {
        $dir = $this->tmp . '/' . $label;
        \mkdir($dir, 0o777, true);
        if ($contents !== null) {
            \file_put_contents($dir . '/composer.json', $contents);
        }
        return Path::create($dir);
    }

    private function providerReturning(Path $extracted): RemoteProvider
    {
        $ref = $this->ref('https://example.com/repo.git', 'v1.0.0');
        $fetcher = new class($extracted) implements RemoteFetcher {
            public function __construct(private readonly Path $extracted) {}

            public function fetch(RemoteDonorRef $ref): Path
            {
                return $this->extracted;
            }
        };
        return new RemoteProvider($this->sourceWithRefs($ref), $fetcher);
    }
}
