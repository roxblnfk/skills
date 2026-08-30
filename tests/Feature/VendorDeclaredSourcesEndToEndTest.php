<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Feature;

use Composer\Composer;
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Package\CompletePackage;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Util\HttpDownloader;
use Internal\Path;
use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\TrustedVendors;
use LLM\Skills\Discovery\Provider\Source\Adapter\GithubAdapter;
use LLM\Skills\Discovery\Provider\Source\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Source\Http\HttpClient;
use LLM\Skills\Discovery\Provider\Source\Http\HttpResponse;
use LLM\Skills\Discovery\Provider\Source\HttpArchiveFetcher;
use LLM\Skills\Discovery\Provider\Source\SourceProvider;
use LLM\Skills\Discovery\Provider\Source\VendorDeclaredRefSource;
use LLM\Skills\Sync\SyncPlanner;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * End-to-end Feature test for vendor-declared external sources
 * (`extra.skills.sources` in an installed package).
 *
 * Substitutes the real network with a counting {@see HttpClient} stub
 * and Composer's local repository with an in-memory one — everything
 * else (vendor mapper, `self.version` binding, adapter, fetcher,
 * provider, planner trust rules) is the production code path. Same
 * tier and rationale as {@see SourceProviderEndToEndTest}: the
 * Acceptance sandbox cannot stub HTTP, so this test pins the
 * in-process contract.
 */
#[Test]
#[Covers(VendorDeclaredRefSource::class)]
#[Covers(SourceProvider::class)]
final class VendorDeclaredSourcesEndToEndTest
{
    private string $tmp;

    /** @var \ArrayObject<string, int> URL → number of times the stub served it */
    private \ArrayObject $httpHits;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-vendor-e2e-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
        $this->httpHits = new \ArrayObject();
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function selfVersionSourceResolvesFetchesAndIsGatedByTheDeclarersTrust(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }

        // An installed package at 1.4.2 advertises a skills repository
        // pinned to its own version. `self.version` must compile to the
        // exact constraint `=1.4.2`, resolve through the tag listing to
        // the `v`-prefixed tag, and download that zipball.
        $composer = $this->composerWith(self::declaringPackage('acme/foo', '1.4.2', [
            ['from' => 'github', 'package' => 'acme/project-skills', 'ref' => 'self.version'],
        ]));

        $zipBytes = $this->buildGithubStyleZip(
            topDir: 'acme-project-skills-v1.4.2',
            files: [
                'composer.json' => \json_encode([
                    'name' => 'acme/project-skills',
                    'extra' => ['skills' => ['source' => 'skills']],
                ], \JSON_THROW_ON_ERROR),
                'skills/deploy/SKILL.md' => "---\nname: deploy\n---\nbody",
            ],
        );

        $http = $this->stubHttp([
            'https://api.github.com/repos/acme/project-skills/tags?per_page=100' => new HttpResponse(
                statusCode: 200,
                body: \json_encode(
                    [['name' => 'v1.0.0'], ['name' => 'v1.4.2'], ['name' => 'v2.0.0']],
                    \JSON_THROW_ON_ERROR,
                ),
            ),
            'https://api.github.com/repos/acme/project-skills/zipball/v1.4.2' => new HttpResponse(
                statusCode: 200,
                body: $zipBytes,
            ),
        ]);

        $provider = $this->providerFor($composer, $http);

        Assert::true($provider->isActive($this->root()));

        $result = $provider->discover($this->root());

        Assert::same($result->failures, []);
        Assert::count($result->donors, 1);
        $donor = $result->donors[0];
        Assert::same($donor->packageName, 'acme/project-skills');
        Assert::same($donor->declaredBy, 'acme/foo');
        Assert::same($donor->provenance, 'github');
        Assert::false($donor->implicitTrust, 'vendor-declared sources must not be implicit-trusted');

        $skillFile = (string) $donor->sourcePath()->join('deploy/SKILL.md');
        Assert::true(\is_file($skillFile), 'extracted skill file must exist at ' . $skillFile);

        // Trust follows the declaring package, not the bundle name:
        // trusting acme/foo approves the donor, an empty trust list
        // skips it.
        $planner = new SyncPlanner();

        $approved = $planner->plan(
            donors: $result->donors,
            project: ProjectConfig::default(),
            options: SyncOptions::default(),
            builtin: TrustedVendors::fromStrings('acme/foo'),
            projectRoot: $this->root(),
        );
        Assert::count($approved->approvedDonors, 1);

        $skipped = $planner->plan(
            donors: $result->donors,
            project: ProjectConfig::default(),
            options: SyncOptions::default(),
            builtin: TrustedVendors::empty(),
            projectRoot: $this->root(),
        );
        Assert::same($skipped->approvedDonors, []);
        Assert::same($skipped->skippedUntrustedNames, ['acme/project-skills']);
    }

    public function sharedBundleDeclaredByTwoPackagesIsFetchedOnceWithUnionedAllowlist(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }

        // The issue's motivating case: several integration packages
        // share one skills bundle. One fetch, one donor, allowlists
        // unioned — and no self-conflict at sync time.
        $composer = $this->composerWith(
            self::declaringPackage('acme/integration-a', '1.0.0', [
                ['from' => 'github', 'package' => 'acme/bundle', 'ref' => 'v1.0.0', 'skills' => ['deploy']],
            ]),
            self::declaringPackage('acme/integration-b', '2.3.0', [
                ['from' => 'github', 'package' => 'acme/bundle', 'ref' => 'v1.0.0', 'skills' => ['review']],
            ]),
        );

        $zipBytes = $this->buildGithubStyleZip(
            topDir: 'acme-bundle-v1.0.0',
            files: [
                'composer.json' => \json_encode([
                    'name' => 'acme/bundle',
                    'extra' => ['skills' => ['source' => 'skills']],
                ], \JSON_THROW_ON_ERROR),
                'skills/deploy/SKILL.md' => "---\nname: deploy\n---\nbody",
                'skills/review/SKILL.md' => "---\nname: review\n---\nbody",
                'skills/other/SKILL.md' => "---\nname: other\n---\nbody",
            ],
        );

        $zipballUrl = 'https://api.github.com/repos/acme/bundle/zipball/v1.0.0';
        $http = $this->stubHttp([
            $zipballUrl => new HttpResponse(statusCode: 200, body: $zipBytes),
        ]);

        $result = $this->providerFor($composer, $http)->discover($this->root());

        Assert::same($result->failures, []);
        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->skillFilter, ['deploy', 'review']);
        Assert::same($this->httpHits[$zipballUrl] ?? 0, 1, 'the shared bundle must be downloaded exactly once');

        // The unioned allowlist drives enumeration: both declared skills
        // land, the undeclared one is dropped.
        $enum = (new \LLM\Skills\Discovery\SkillEnumerator())->enumerate($result->donors);
        $names = \array_map(static fn($s) => $s->name, $enum->skills);
        \sort($names);
        Assert::same($names, ['deploy', 'review']);
    }

    public function vendorSourcesFalseSilencesTheWholeSourceWithoutAnyHttp(): void
    {
        // The project-level kill switch: with `vendor-sources: false`
        // in skills.json the provider is inactive, nothing is fetched,
        // nothing fails. The stub would throw on any HTTP call.
        \file_put_contents(
            $this->tmp . '/skills.json',
            \json_encode(['vendor-sources' => false], \JSON_THROW_ON_ERROR),
        );
        $composer = $this->composerWith(self::declaringPackage('acme/foo', '1.0.0', [
            ['from' => 'github', 'package' => 'acme/project-skills', 'ref' => 'v1.0.0'],
        ]));

        $provider = $this->providerFor($composer, $this->stubHttp([]));

        Assert::false($provider->isActive($this->root()));

        $result = $provider->discover($this->root());
        Assert::same($result->donors, []);
        Assert::same($result->failures, []);
    }

    // ── helpers ──

    private function root(): Path
    {
        return Path::create($this->tmp);
    }

    private function providerFor(Composer $composer, HttpClient $http): SourceProvider
    {
        return new SourceProvider(
            new VendorDeclaredRefSource($composer, new HostAdapterRegistry(new GithubAdapter($http))),
            new HttpArchiveFetcher($http, $this->root()),
        );
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $version
     * @param list<array<string, mixed>> $sources
     */
    private static function declaringPackage(string $name, string $version, array $sources): CompletePackage
    {
        $package = new CompletePackage($name, $version, $version);
        $package->setType('library');
        $package->setExtra(['skills' => ['sources' => $sources]]);

        return $package;
    }

    /**
     * Build a Composer whose local repository returns exactly the given
     * packages — the ref source only reads repository metadata, so no
     * installation manager or filesystem is involved.
     */
    private function composerWith(PackageInterface ...$packages): Composer
    {
        $io = new NullIO();
        $config = new Config(false, \sys_get_temp_dir());

        $local = new InstalledArrayRepository();
        foreach ($packages as $package) {
            $local->addPackage($package);
        }

        $repositoryManager = new RepositoryManager($io, $config, new HttpDownloader($io, $config));
        $repositoryManager->setLocalRepository($local);

        $composer = new Composer();
        $composer->setConfig($config);
        $composer->setRepositoryManager($repositoryManager);

        return $composer;
    }

    /**
     * @param array<string, HttpResponse> $responses
     */
    private function stubHttp(array $responses): HttpClient
    {
        return new class($responses, $this->httpHits) implements HttpClient {
            /**
             * @param array<string, HttpResponse> $responses
             * @param \ArrayObject<string, int> $hits
             */
            public function __construct(
                private readonly array $responses,
                private readonly \ArrayObject $hits,
            ) {}

            #[\Override]
            public function get(string $url, array $headers = []): HttpResponse
            {
                if (!isset($this->responses[$url])) {
                    throw new \LogicException('unstubbed URL: ' . $url);
                }
                $this->hits[$url] = ($this->hits[$url] ?? 0) + 1;
                return $this->responses[$url];
            }
        };
    }

    /**
     * Build an in-memory zip with a single top-level directory wrapping
     * the given files — the same shape GitHub's zipball endpoint
     * returns.
     *
     * @param non-empty-string $topDir
     * @param array<string, string> $files relative paths inside the top dir → file contents
     */
    private function buildGithubStyleZip(string $topDir, array $files): string
    {
        $tmpZip = \tempnam(\sys_get_temp_dir(), 'vendor-e2e-zip-');
        if ($tmpZip === false) {
            throw new \RuntimeException('failed to create tmp zip');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('failed to open zip for write');
        }
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
}
