<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Feature;

use Internal\Path;
use LLM\Skills\Discovery\Provider\Remote\Adapter\GithubAdapter;
use LLM\Skills\Discovery\Provider\Remote\Adapter\GitlabAdapter;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Remote\Http\HttpClient;
use LLM\Skills\Discovery\Provider\Remote\Http\HttpResponse;
use LLM\Skills\Discovery\Provider\Remote\HttpArchiveFetcher;
use LLM\Skills\Discovery\Provider\Remote\RemoteProvider;
use LLM\Skills\Discovery\Provider\Remote\SkillsJsonRemoteDonorSource;
use LLM\Skills\Tests\Testo\Filesystem;
use LLM\Skills\Unpacker\ArchiveUnpacker;
use LLM\Skills\Unpacker\CliUnpacker;
use LLM\Skills\Unpacker\UnpackerFactory;
use Symfony\Component\Process\ExecutableFinder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
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
#[Covers(GitlabAdapter::class)]
#[Covers(HttpArchiveFetcher::class)]
final class RemoteProviderEndToEndTest
{
    private string $tmp;

    public function SourceEntryResolvesFetchesAndProducesDonor(): void
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
        // Every `remote[]` donor is implicit-trusted, so
        // {@see \LLM\Skills\Sync\SyncPlanner} approves it without
        // consulting the trust list or the direct-dep short-circuit.
        Assert::true($result->donors[0]->implicitTrust);

        // The donor's package root is the cache path the fetcher
        // returned. Verify the extracted skill file actually landed.
        $skillFile = (string) $result->donors[0]->sourcePath()->join('hello/SKILL.md');
        Assert::true(\is_file($skillFile), 'extracted skill file must exist at ' . $skillFile);
        Assert::true(\str_contains((string) \file_get_contents($skillFile), 'name: hello'));
    }

    public function gitlabSourceEntryResolvesFetchesAndProducesDonor(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }

        // Same end-to-end shape as the GitHub success case, but through
        // the GitLab adapter. The point worth pinning here is the
        // GitLab-specific download URL: a `archive.zip?sha=<ref>`
        // endpoint with a URL-encoded project id (`acme%2Fskills`) and
        // a query string — unlike GitHub's path-only zipball URL. The
        // fetcher and cache must carry that URL through unchanged.
        \file_put_contents($this->tmp . '/skills.json', \json_encode([
            'remote' => [
                ['from' => 'gitlab', 'package' => 'acme/skills', 'ref' => 'v1.0.0'],
            ],
        ], \JSON_THROW_ON_ERROR));

        // GitLab archives wrap everything in a single top-level dir
        // named `{project}-{ref}-{sha}`; the exact name is irrelevant to
        // the fetcher, which only requires there to be exactly one.
        $zipBytes = $this->buildGithubStyleZip(
            topDir: 'skills-v1.0.0-0abc123def',
            files: [
                'composer.json' => \json_encode([
                    'name' => 'acme/skills',
                    'extra' => ['skills' => ['source' => 'skills']],
                ], \JSON_THROW_ON_ERROR),
                'skills/hello/SKILL.md' => "---\nname: hello\n---\nhi",
            ],
        );

        $http = self::stubHttp([
            // Literal `v1.0.0` resolves directly to the archive endpoint
            // — no tag listing needed.
            'https://gitlab.com/api/v4/projects/acme%2Fskills/repository/archive.zip?sha=v1.0.0' =>
                new HttpResponse(statusCode: 200, body: $zipBytes),
        ]);

        $provider = new RemoteProvider(
            new SkillsJsonRemoteDonorSource(new HostAdapterRegistry(new GitlabAdapter($http))),
            new HttpArchiveFetcher($http, Path::create($this->tmp)),
        );

        Assert::true($provider->isActive(Path::create($this->tmp)));

        $result = $provider->discover(Path::create($this->tmp));

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->packageName, 'acme/skills');
        Assert::same($result->donors[0]->provenance, 'gitlab');
        Assert::true($result->donors[0]->implicitTrust);

        $skillFile = (string) $result->donors[0]->sourcePath()->join('hello/SKILL.md');
        Assert::true(\is_file($skillFile), 'extracted skill file must exist at ' . $skillFile);
        Assert::true(\str_contains((string) \file_get_contents($skillFile), 'name: hello'));
    }

    public function nestedBareSkillRepoIsDiscoveredAndEnumeratedEndToEnd(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }

        // Mirrors ArtemProshkovskiy/laravel-maintenance-skills: no
        // composer.json, and the skill is nested at
        // `maintenance/skills/<name>/`. The recursive scanner finds it
        // (the old single-root probe could not), and the enumerator copies
        // it through `discoveredSkillDirs`.
        \file_put_contents($this->tmp . '/skills.json', \json_encode([
            'remote' => [
                ['from' => 'github', 'package' => 'artem/maintenance', 'ref' => 'v1.0.0'],
            ],
        ], \JSON_THROW_ON_ERROR));

        $zipBytes = $this->buildGithubStyleZip(
            topDir: 'artem-maintenance-v1.0.0',
            files: [
                'README.md' => '# no composer.json here',
                'maintenance/skills/triage/SKILL.md' => "---\nname: triage\n---\nbody",
            ],
        );

        $http = self::stubHttp([
            'https://api.github.com/repos/artem/maintenance/zipball/v1.0.0' => new HttpResponse(
                statusCode: 200,
                body: $zipBytes,
            ),
        ]);

        $provider = new RemoteProvider(
            new SkillsJsonRemoteDonorSource(new HostAdapterRegistry(new GithubAdapter($http))),
            new HttpArchiveFetcher($http, Path::create($this->tmp)),
        );

        $result = $provider->discover(Path::create($this->tmp));

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->packageName, 'artem/maintenance');
        // Remote refs are explicit (the user typed skills:add), so they are
        // never gated behind --discovery even when the skills were auto-found.
        Assert::false($result->donors[0]->discovered);

        // End-to-end enumeration: the nested skill is resolved via the
        // donor's explicit discoveredSkillDirs, not a source-subdir scan.
        $enum = (new \LLM\Skills\Discovery\SkillEnumerator())->enumerate($result->donors);
        $names = \array_map(static fn($s) => $s->name, $enum->skills);
        Assert::same($names, ['triage']);
    }

    public function remoteSkillsAllowlistThreadsIntoTheDonor(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }

        // skills.json declares a github donor with an explicit allowlist
        // (`hello` only). The provider must build a VendorConfig whose
        // skillFilter matches that list verbatim, which is what the
        // enumerator downstream uses to drop everything else and warn
        // on the typo.
        \file_put_contents($this->tmp . '/skills.json', \json_encode([
            'remote' => [
                [
                    'from' => 'github',
                    'package' => 'acme/skills',
                    'ref' => 'v1.0.0',
                    'skills' => ['hello', 'missing-on-purpose'],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $zipBytes = $this->buildGithubStyleZip(
            topDir: 'acme-skills-v1.0.0',
            files: [
                'composer.json' => \json_encode([
                    'name' => 'acme/skills',
                    'extra' => ['skills' => ['source' => 'skills']],
                ], \JSON_THROW_ON_ERROR),
                'skills/hello/SKILL.md' => "---\nname: hello\n---\nhi",
                'skills/other/SKILL.md' => "---\nname: other\n---\nbye",
            ],
        );

        $http = self::stubHttp([
            'https://api.github.com/repos/acme/skills/zipball/v1.0.0' => new HttpResponse(
                statusCode: 200,
                body: $zipBytes,
            ),
        ]);

        $provider = new RemoteProvider(
            new SkillsJsonRemoteDonorSource(new HostAdapterRegistry(new GithubAdapter($http))),
            new HttpArchiveFetcher($http, Path::create($this->tmp)),
        );

        $result = $provider->discover(Path::create($this->tmp));

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->skillFilter, ['hello', 'missing-on-purpose']);

        // Run the enumerator over the produced donor and verify the
        // contract end-to-end: only `hello` lands, `other` is dropped
        // silently (not on the allowlist), `missing-on-purpose` emits
        // a warning.
        $enum = (new \LLM\Skills\Discovery\SkillEnumerator())->enumerate($result->donors);

        $names = \array_map(static fn($s) => $s->name, $enum->skills);
        Assert::same($names, ['hello']);
        $combined = \implode("\n", $enum->warnings);
        Assert::true(\str_contains($combined, 'missing-on-purpose'));
        Assert::true(\str_contains($combined, 'acme/skills'));
    }

    public function zipSlipArchiveIsRejectedBeforeAnyFileLands(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }

        $this->runZipSlipScenario(unpacker: null);
    }

    public function zipSlipArchiveIsAlsoRejectedOnTheCliFallbackPath(): void
    {
        // The CLI unpacker (unzip / 7z) has no built-in zip-slip
        // protection — the safety boundary is owned by the fetcher's
        // pre-extraction lexical check against the CDR. This scenario
        // pins that path explicitly: even though ext-zip happens to be
        // installed, we force the fetcher onto the CLI unpacker and
        // verify the same rejection lands.
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }
        if (!\function_exists('proc_open')) {
            Assert::true(true);
            return;
        }

        $cli = (new UnpackerFactory(
            finder: new ExecutableFinder(),
            hasZipArchive: static fn(): bool => false,
            hasProcOpen: static fn(): bool => true,
        ))->detect();
        if (!$cli instanceof CliUnpacker) {
            // No `unzip` / `7z*` on this machine — the CLI path is not
            // exercisable. The ZipArchive variant above still ran.
            Assert::true(true);
            return;
        }

        $this->runZipSlipScenario(unpacker: $cli);
    }

    public function malformedRemoteArchiveProducesWarning(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }

        // Build a zip that's missing composer.json AND any SKILL.md —
        // neither donor shape (Composer-shaped or bare skill repo)
        // applies, so the provider rejects the archive and surfaces a
        // single combined warning. With a SKILL.md present, the same
        // archive would auto-discover (covered by the dedicated unit test).
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
        Assert::string($result->warnings[0])
            ->contains('neither a composer.json')
            ->contains('SKILL.md');
    }

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

    /**
     * @param array<string, HttpResponse> $responses
     */
    private static function stubHttp(array $responses): HttpClient
    {
        return new class($responses) implements HttpClient {
            /**
             * @param array<string, HttpResponse> $responses
             */
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

    private function runZipSlipScenario(?ArchiveUnpacker $unpacker): void
    {
        // A malicious archive served by a user-configurable `host`
        // contains an entry whose name resolves outside the
        // extraction scratch dir. The fetcher must refuse the archive
        // and emit a warning; no file may land at the traversal
        // target. Mirrors the OWASP "zip slip" vulnerability.
        \file_put_contents($this->tmp . '/skills.json', \json_encode([
            'remote' => [
                ['from' => 'github', 'package' => 'evil/payload', 'ref' => 'v1.0.0'],
            ],
        ], \JSON_THROW_ON_ERROR));

        // Build a zip with one well-shaped entry + one traversal
        // entry. extractTo() would happily honour the `..` if we did
        // not pre-validate.
        $zipBytes = $this->buildRawZip([
            'evil-payload-v1.0.0/' => '',
            'evil-payload-v1.0.0/composer.json' => '{}',
            '../../pwn.txt' => 'gotcha',
        ]);

        $http = self::stubHttp([
            'https://api.github.com/repos/evil/payload/zipball/v1.0.0' => new HttpResponse(
                statusCode: 200,
                body: $zipBytes,
            ),
        ]);

        $provider = new RemoteProvider(
            new SkillsJsonRemoteDonorSource(new HostAdapterRegistry(new GithubAdapter($http))),
            new HttpArchiveFetcher($http, Path::create($this->tmp), unpacker: $unpacker),
        );

        $result = $provider->discover(Path::create($this->tmp));

        Assert::same($result->donors, []);
        Assert::true($result->warnings !== []);
        $combined = \implode("\n", $result->warnings);
        Assert::true(
            \str_contains($combined, 'unsafe entry path'),
            'fetcher must explicitly reject the traversal entry — got: ' . $combined,
        );

        // The traversal target must not exist. Both the parent dir
        // (one level above $this->tmp) and the sibling pwn file must
        // be absent.
        $traversalTarget = \dirname($this->tmp, 2) . '/pwn.txt';
        Assert::false(\is_file($traversalTarget), 'zip-slip wrote to ' . $traversalTarget);
    }

    /**
     * Build an in-memory zip with arbitrary entry names — used for
     * adversarial cases (e.g. zip-slip traversal). A trailing slash on
     * a key marks the entry as a directory (empty content).
     *
     * @param array<string, string> $entries entry-name → file contents
     */
    private function buildRawZip(array $entries): string
    {
        $tmpZip = \tempnam(\sys_get_temp_dir(), 'feature-raw-zip-');
        if ($tmpZip === false) {
            throw new \RuntimeException('failed to create tmp zip');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('failed to open zip for write');
        }
        foreach ($entries as $name => $content) {
            if (\str_ends_with($name, '/')) {
                $zip->addEmptyDir(\rtrim($name, '/'));
            } else {
                $zip->addFromString($name, $content);
            }
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
}
