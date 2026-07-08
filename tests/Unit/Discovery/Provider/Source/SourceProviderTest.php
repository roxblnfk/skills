<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider\Source;

use Internal\Path;
use LLM\Skills\Discovery\Provider\Source\DirDonorRef;
use LLM\Skills\Discovery\Provider\Source\NullDonorRefSource;
use LLM\Skills\Discovery\Provider\Source\RemoteDonorRef;
use LLM\Skills\Discovery\Provider\Source\DonorRefSource;
use LLM\Skills\Discovery\Provider\Source\RemoteFetchException;
use LLM\Skills\Discovery\Provider\Source\RemoteFetcher;
use LLM\Skills\Discovery\Provider\Source\SourceProvider;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Unit coverage for {@see SourceProvider}.
 *
 * Each test wires a small test-double {@see DonorRefSource} +
 * {@see RemoteFetcher} pair and verifies how the provider handles
 * the resulting (success / failure) signal. The fetcher's "extract
 * to disk" is faked by writing a `composer.json` into a temp dir
 * and returning its path — the provider is filesystem-aware and
 * the test must exercise that boundary, but no real network IO
 * happens.
 */
#[Test]
#[Covers(SourceProvider::class)]
#[Covers(NullDonorRefSource::class)]
#[Covers(RemoteDonorRef::class)]
#[Covers(DirDonorRef::class)]
#[Covers(RemoteFetchException::class)]
final class SourceProviderTest
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
        $provider = new SourceProvider(new NullDonorRefSource());

        Assert::false($provider->isActive($this->projectRoot()));
        $result = $provider->discover($this->projectRoot());
        Assert::same($result->donors, []);
        Assert::same($result->warnings, []);
    }

    public function activeWhenSourceYieldsAtLeastOneRef(): void
    {
        $provider = new SourceProvider(
            $this->sourceWithRefs($this->ref('https://example.com/repo.git', 'main')),
        );

        Assert::true($provider->isActive($this->projectRoot()));
    }

    public function warnsOnceWhenSourceActiveButFetcherMissing(): void
    {
        // Misconfiguration is global, not per-ref — one warning is
        // more useful than N copies of the same message.
        $provider = new SourceProvider(
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

        $provider = new SourceProvider($this->sourceWithRefs($ref), $fetcher);
        $result = $provider->discover($this->projectRoot());

        Assert::same($result->donors, []);
        Assert::count($result->warnings, 1);
        Assert::true(\str_contains($result->warnings[0], 'host unreachable'));
        Assert::true(\str_contains($result->warnings[0], $ref->describe()));
    }

    public function warnsWhenNeitherComposerJsonNorSkillMdIsPresent(): void
    {
        // No composer.json AND no SKILL.md anywhere — both donor shapes
        // (Composer-shaped and bare skill-repo) fail, so the provider
        // emits a single combined warning.
        $extracted = $this->makeExtracted('archive-without-anything');

        $provider = $this->providerReturning($extracted);
        $result = $provider->discover($this->projectRoot());

        Assert::same($result->donors, []);
        Assert::count($result->warnings, 1);
        Assert::true(\str_contains($result->warnings[0], 'neither a composer.json'));
        Assert::true(\str_contains($result->warnings[0], 'SKILL.md'));
    }

    public function autoDiscoversBareSkillsRepoFromPackageHint(): void
    {
        // No composer.json, but the archive ships a `skills/` directory
        // — the shape ad-hoc Claude/agent skill packs take. The
        // provider falls back to the entry's `packageHint` (the
        // adapter-side identifier, e.g. GitHub repo path) for the
        // donor's name and synthesises a discoverable VendorConfig.
        $extracted = $this->makeExtracted('bare-skill-repo');
        $this->writeSkill($extracted);

        $provider = $this->providerReturning($extracted, packageHint: 'acme/skills');
        $result = $provider->discover($this->projectRoot());

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->packageName, 'acme/skills');
        Assert::same($result->donors[0]->source, 'skills');
        // Remote donors are user-declared (skills:add); `discovered`
        // (the "auto-found, gate behind --discovery" flag) does NOT
        // apply even when the source dir was auto-probed.
        Assert::false($result->donors[0]->discovered);
        Assert::same($result->warnings, []);
    }

    public function autoDiscoversSkillsNestedBelowConventionalRoots(): void
    {
        // Mirrors ArtemProshkovskiy/laravel-maintenance-skills: no
        // composer.json, and the skill lives at `maintenance/skills/<name>/`.
        // The single-root probe missed this; the recursive scan finds it.
        $extracted = $this->makeExtracted('nested-skill-repo');
        $this->writeSkill($extracted, 'maintenance/skills/composer-dependency-triage');

        $provider = $this->providerReturning($extracted, packageHint: 'artem/maintenance');
        $result = $provider->discover($this->projectRoot());

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->packageName, 'artem/maintenance');
        Assert::false($result->donors[0]->discovered);
        Assert::same($result->warnings, []);
    }

    public function warnsWhenBareSkillsRepoHasNoPackageHint(): void
    {
        // `skills/` is present but the ref carries no `packageHint` —
        // no stable identifier to register the donor under.
        $extracted = $this->makeExtracted('orphan-skill-repo');
        $this->writeSkill($extracted);

        $provider = $this->providerReturning($extracted, packageHint: null);
        $result = $provider->discover($this->projectRoot());

        Assert::same($result->donors, []);
        Assert::count($result->warnings, 1);
        Assert::true(\str_contains($result->warnings[0], 'no package name'));
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

    public function fallsBackToPackageHintWhenComposerJsonHasNoName(): void
    {
        // composer.json declares extra.skills.source but no `name`.
        // Previously this was a hard reject; now the provider falls
        // through to the auto-discovery path. The fallback requires a
        // `skills/` directory at root AND a packageHint — both are
        // satisfied here, so a donor is synthesised.
        $extracted = $this->makeExtracted(
            'no-name',
            \json_encode(['extra' => ['skills' => ['source' => 'skills']]]),
        );
        $this->writeSkill($extracted);

        $provider = $this->providerReturning($extracted, packageHint: 'acme/skills');
        $result = $provider->discover($this->projectRoot());

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->packageName, 'acme/skills');
    }

    public function fallsBackToPackageHintWhenComposerJsonNameMissesVendorSlash(): void
    {
        // `name: "flat"` (no vendor/package shape) is treated the same
        // as missing — fall through to auto-discovery using the hint.
        $extracted = $this->makeExtracted(
            'flat-name',
            \json_encode(['name' => 'flat', 'extra' => ['skills' => ['source' => 'skills']]]),
        );
        $this->writeSkill($extracted);

        $provider = $this->providerReturning($extracted, packageHint: 'acme/skills');
        $result = $provider->discover($this->projectRoot());

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->packageName, 'acme/skills');
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

        $provider = new SourceProvider($this->sourceWithRefs($refBad, $refGood), $fetcher);
        $result = $provider->discover($this->projectRoot());

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->packageName, 'acme/pkg');
        Assert::count($result->warnings, 1);
    }

    // ── dir refs ──────────────────────────────────────────────────────────────

    public function dirRefReadsLiveDirectoryAndDecoratesDonor(): void
    {
        // A dir ref carries a resolved directory; the provider reads it
        // in place (no fetcher wired) and decorates the donor with the
        // ref's `dir` provenance + implicit trust.
        $dir = $this->makeExtracted(
            'live-dir',
            \json_encode([
                'name' => 'acme/local',
                'extra' => ['skills' => ['source' => 'skills']],
            ]),
        );

        $provider = new SourceProvider(
            $this->sourceWithMixedRefs($this->dirRef($dir, packageHint: 'acme/local')),
        );
        $result = $provider->discover($this->projectRoot());

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->packageName, 'acme/local');
        Assert::same($result->donors[0]->provenance, 'dir');
        Assert::true($result->donors[0]->implicitTrust);
        Assert::same($result->warnings, []);
    }

    public function dirRefMissingDirectoryWarnsAndSkipsWithoutBlockingSiblings(): void
    {
        // First dir ref points at a directory that does not exist → a
        // per-entry warning, skipped. The sibling dir ref still produces
        // its donor.
        $missing = Path::create($this->tmp . '/does-not-exist');
        $good = $this->makeExtracted(
            'good-dir',
            \json_encode([
                'name' => 'acme/good',
                'extra' => ['skills' => ['source' => 'skills']],
            ]),
        );

        $provider = new SourceProvider($this->sourceWithMixedRefs(
            $this->dirRef($missing, spelling: './missing'),
            $this->dirRef($good, spelling: './good', packageHint: 'acme/good'),
        ));
        $result = $provider->discover($this->projectRoot());

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->packageName, 'acme/good');
        Assert::count($result->warnings, 1);
        Assert::true(\str_contains($result->warnings[0], 'dir ./missing'));
        Assert::true(\str_contains($result->warnings[0], 'directory does not exist'));
    }

    public function dirRefBareSkillDirUsesDerivedHintForItsName(): void
    {
        // No composer.json, but the directory ships SKILL.md files: the
        // donor name comes from the ref's packageHint (the derived
        // `<parent>/<basename>` name the source computed).
        $dir = $this->makeExtracted('bare-dir');
        $this->writeSkill($dir);

        $provider = new SourceProvider(
            $this->sourceWithMixedRefs($this->dirRef($dir, packageHint: 'team/skills')),
        );
        $result = $provider->discover($this->projectRoot());

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->packageName, 'team/skills');
        Assert::false($result->donors[0]->discovered);
        Assert::same($result->warnings, []);
    }

    public function dirRefComposerShapedUsesItsOwnComposerName(): void
    {
        // The directory carries a composer.json with a name + skills
        // source; that name wins over the ref's derived packageHint.
        $dir = $this->makeExtracted(
            'composer-dir',
            \json_encode([
                'name' => 'real/name',
                'extra' => ['skills' => ['source' => 'skills']],
            ]),
        );

        $provider = new SourceProvider(
            $this->sourceWithMixedRefs($this->dirRef($dir, packageHint: 'derived/hint')),
        );
        $result = $provider->discover($this->projectRoot());

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->packageName, 'real/name');
    }

    public function dirRefAllowlistThreadsIntoDonor(): void
    {
        $dir = $this->makeExtracted(
            'allowlist-dir',
            \json_encode([
                'name' => 'acme/local',
                'extra' => ['skills' => ['source' => 'skills']],
            ]),
        );
        $this->writeSkill($dir);

        $provider = new SourceProvider($this->sourceWithMixedRefs(
            $this->dirRef($dir, packageHint: 'acme/local', skillFilter: ['example']),
        ));
        $result = $provider->discover($this->projectRoot());

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->skillFilter, ['example']);
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

    /**
     * @param non-empty-string $spelling
     * @param non-empty-string|null $packageHint
     * @param list<non-empty-string>|null $skillFilter
     */
    private function dirRef(
        Path $directory,
        string $spelling = './dir-src',
        ?string $packageHint = 'acme/local',
        ?array $skillFilter = null,
    ): DirDonorRef {
        return new DirDonorRef(
            directory: $directory,
            spelling: $spelling,
            provenance: 'dir',
            skillFilter: $skillFilter,
            packageHint: $packageHint,
        );
    }

    private function sourceWithMixedRefs(RemoteDonorRef|DirDonorRef ...$refs): DonorRefSource
    {
        return new class($refs) implements DonorRefSource {
            /** @param list<RemoteDonorRef|DirDonorRef> $refs */
            public function __construct(private readonly array $refs) {}

            #[\Override]
            public function refs(Path $projectRoot): iterable
            {
                return $this->refs;
            }

            #[\Override]
            public function warnings(): array
            {
                return [];
            }

            #[\Override]
            public function hasRefs(Path $projectRoot): bool
            {
                return $this->refs !== [];
            }
        };
    }

    private function sourceWithRefs(RemoteDonorRef ...$refs): DonorRefSource
    {
        return new class($refs) implements DonorRefSource {
            /** @param list<RemoteDonorRef> $refs */
            public function __construct(private readonly array $refs) {}

            #[\Override]
            public function refs(Path $projectRoot): iterable
            {
                return $this->refs;
            }

            #[\Override]
            public function warnings(): array
            {
                return [];
            }

            #[\Override]
            public function hasRefs(Path $projectRoot): bool
            {
                return $this->refs !== [];
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

    /**
     * Drop a real skill (a directory holding `SKILL.md`) into an extracted
     * archive so the recursive auto-discovery scanner has something to find.
     */
    private function writeSkill(Path $extracted, string $relDir = 'skills/example'): void
    {
        $dir = (string) $extracted . '/' . $relDir;
        \mkdir($dir, 0o777, true);
        \file_put_contents($dir . '/SKILL.md', "---\nname: example\n---\nbody");
    }

    /**
     * @param non-empty-string|null $packageHint optional adapter-side identifier
     *         (e.g. GitHub `<owner>/<repo>`). Threaded into the {@see RemoteDonorRef}
     *         so the auto-discovery fallback in {@see SourceProvider} can pick it
     *         up. Defaults to a non-null hint so existing tests keep working.
     */
    private function providerReturning(Path $extracted, ?string $packageHint = 'acme/pkg'): SourceProvider
    {
        $ref = new RemoteDonorRef(
            url: 'https://example.com/repo.git',
            ref: 'v1.0.0',
            packageHint: $packageHint,
        );
        $fetcher = new class($extracted) implements RemoteFetcher {
            public function __construct(private readonly Path $extracted) {}

            public function fetch(RemoteDonorRef $ref): Path
            {
                return $this->extracted;
            }
        };
        return new SourceProvider($this->sourceWithRefs($ref), $fetcher);
    }
}
