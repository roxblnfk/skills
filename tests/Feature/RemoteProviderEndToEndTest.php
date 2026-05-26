<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Feature;

use Internal\Path;
use LLM\Skills\Config\RemoteEntry;
use LLM\Skills\Discovery\Provider\ProviderId;
use LLM\Skills\Discovery\Provider\Remote\Adapter\GithubAdapter;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Remote\Http\HttpClient;
use LLM\Skills\Discovery\Provider\Remote\Http\HttpResponse;
use LLM\Skills\Discovery\Provider\Remote\HttpArchiveFetcher;
use LLM\Skills\Discovery\Provider\Remote\RemoteDonorRef;
use LLM\Skills\Discovery\Provider\Remote\RemoteFetcher;
use LLM\Skills\Discovery\Provider\Remote\RemoteProvider;
use LLM\Skills\Discovery\Provider\Remote\SkillsJsonRemoteDonorSource;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * End-to-end Feature test for the remote pipeline.
 *
 * Substitutes the real network with a {@see HttpClient} stub that
 * serves prebuilt zip bytes — everything else (config mapper, adapter
 * registry, source, fetcher, provider) is the production code path.
 *
 * This is the "between Unit and Acceptance" tier described in
 * `testo.php` — too many test-doubles for a Unit test (every
 * collaborator participates), but no Composer subprocess or sandbox
 * is needed.
 *
 * A future Phase-7 follow-up will spin up a real `php -S` HTTP
 * fixture so Acceptance tests can exercise the same path through
 * the standalone `bin/skills` binary; this test pins the in-process
 * contract until that lands.
 */
#[Test]
#[Covers(RemoteProvider::class)]
#[Covers(SkillsJsonRemoteDonorSource::class)]
#[Covers(GithubAdapter::class)]
#[Covers(HttpArchiveFetcher::class)]
final class RemoteProviderEndToEndTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-e2e-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function remoteEntryResolvesFetchesAndProducesDonor(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            // ext-zip is a soft dependency; without it the fetcher
            // rejects everything up front and this scenario cannot
            // exercise the success path. Skip rather than fail.
            Assert::true(true, 'ext-zip unavailable — pipeline skipped');
            return;
        }

        // Drive: skills.json declares one github donor at a fixed
        // version, pinning a single API + zipball download.
        \file_put_contents($this->tmp . '/skills.json', \json_encode([
            'remote' => [
                ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v1.0.0'],
            ],
        ], \JSON_THROW_ON_ERROR));

        // Build a zipball that mirrors GitHub's layout: a single
        // top-level dir wrapping a Composer-shaped package.
        $zipBytes = $this->buildGithubStyleZip(
            topDir: 'acme-skills-v1.0.0',
            files: [
                'composer.json' => \json_encode([
                    'name' => 'acme/skills',
                    'extra' => ['skills' => ['source' => 'skills']],
                ], \JSON_THROW_ON_ERROR),
                'skills/hello/SKILL.md' => "---\nname: hello\n---\nhi",
            ],
        );

        $http = self::stubHttp([
            // Adapter resolves the explicit ref directly — no tag listing
            // is required for a literal `v1.0.0`, so only the zipball URL
            // gets hit.
            'https://api.github.com/repos/acme/skills/zipball/v1.0.0' => new HttpResponse(
                statusCode: 200,
                body: $zipBytes,
            ),
        ]);

        $registry = new HostAdapterRegistry(new GithubAdapter($http));
        $source = new SkillsJsonRemoteDonorSource($registry);
        $fetcher = new HttpArchiveFetcher($http, Path::create($this->tmp));
        $provider = new RemoteProvider($source, $fetcher);

        Assert::true($provider->isActive(Path::create($this->tmp)));

        $result = $provider->discover(Path::create($this->tmp));

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->packageName, 'acme/skills');
        Assert::same($result->donors[0]->provenance, 'github');
        // Spec §8.3: every `remote[]` donor is implicit-trusted, so
        // {@see \LLM\Skills\Sync\SyncPlanner} approves it without
        // consulting the trust list or the direct-dep short-circuit.
        Assert::true($result->donors[0]->implicitTrust);

        // The donor's package root is the cache path the fetcher
        // returned. Verify the extracted skill file actually landed.
        $skillFile = (string) $result->donors[0]->sourcePath()->join('hello/SKILL.md');
        Assert::true(\is_file($skillFile), 'extracted skill file must exist at ' . $skillFile);
        Assert::true(\str_contains((string) \file_get_contents($skillFile), 'name: hello'));
    }

    public function malformedRemoteArchiveProducesWarning(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            Assert::true(true);
            return;
        }

        // Build a zip that's missing composer.json — the fetcher
        // succeeds (we have bytes), but the provider rejects the
        // archive as not-a-donor.
        \file_put_contents($this->tmp . '/skills.json', \json_encode([
            'remote' => [
                ['from' => 'github', 'package' => 'acme/empty', 'ref' => 'v1.0.0'],
            ],
        ], \JSON_THROW_ON_ERROR));

        $zipBytes = $this->buildGithubStyleZip(
            topDir: 'acme-empty-v1.0.0',
            files: ['README.md' => 'no composer.json here'],
        );

        $http = self::stubHttp([
            'https://api.github.com/repos/acme/empty/zipball/v1.0.0' => new HttpResponse(
                statusCode: 200,
                body: $zipBytes,
            ),
        ]);

        $provider = new RemoteProvider(
            new SkillsJsonRemoteDonorSource(new HostAdapterRegistry(new GithubAdapter($http))),
            new HttpArchiveFetcher($http, Path::create($this->tmp)),
        );

        $result = $provider->discover(Path::create($this->tmp));

        Assert::same($result->donors, []);
        Assert::true($result->warnings !== []);
        Assert::true(\str_contains((string) $result->warnings[0], 'composer.json missing'));
    }

    /**
     * Build an in-memory zip with a single top-level directory wrapping
     * the given files — the same shape GitHub's zipball endpoint
     * returns. The fetcher's "single top-level dir" assumption depends
     * on this layout.
     *
     * @param non-empty-string $topDir
     * @param array<string, string> $files relative paths inside the top dir → file contents
     */
    private function buildGithubStyleZip(string $topDir, array $files): string
    {
        $tmpZip = \tempnam(\sys_get_temp_dir(), 'feature-zip-');
        if ($tmpZip === false) {
            throw new \RuntimeException('failed to create tmp zip');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('failed to open zip for write');
        }
        // GitHub zipballs include an explicit top-level directory
        // entry so the extracted tree has a clear root. Mimic that.
        $zip->addEmptyDir($topDir);
        foreach ($files as $relPath => $content) {
            $zip->addFromString($topDir . '/' . $relPath, $content);
        }
        $zip->close();

        $bytes = \file_get_contents($tmpZip);
        @\unlink($tmpZip);
        if ($bytes === false) {
            throw new \RuntimeException('failed to read built zip');
        }
        return $bytes;
    }

    /**
     * @param array<string, HttpResponse> $responses
     */
    private static function stubHttp(array $responses): HttpClient
    {
        return new class($responses) implements HttpClient {
            /** @param array<string, HttpResponse> $responses */
            public function __construct(
                private readonly array $responses,
            ) {}

            #[\Override]
            public function get(string $url, array $headers = []): HttpResponse
            {
                if (!isset($this->responses[$url])) {
                    throw new \LogicException('unstubbed URL: ' . $url);
                }
                return $this->responses[$url];
            }
        };
    }
}
