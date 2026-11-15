<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider\Source;

use Internal\Path;
use LLM\Skills\Config\SourceEntry;
use LLM\Skills\Discovery\Provider\Source\Adapter\HostAdapter;
use LLM\Skills\Discovery\Provider\Source\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Source\Adapter\ParsedAddInput;
use LLM\Skills\Discovery\Provider\Source\Adapter\RemoteResolveException;
use LLM\Skills\Discovery\Provider\Source\DirDonorRef;
use LLM\Skills\Discovery\Provider\Source\RemoteDonorRef;
use LLM\Skills\Discovery\Provider\Source\SkillsJsonDonorRefSource;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(SkillsJsonDonorRefSource::class)]
final class SkillsJsonDonorRefSourceTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-source-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function emptyWhenNoSkillsJson(): void
    {
        $source = new SkillsJsonDonorRefSource(new HostAdapterRegistry());

        Assert::same($this->collect($source->refs(Path::create($this->tmp))), []);
        Assert::same($source->failures(), []);
    }

    public function emptyWhenSkillsJsonHasNoRemote(): void
    {
        $this->writeSkillsJson(['target' => '.agents/skills']);

        $source = new SkillsJsonDonorRefSource(new HostAdapterRegistry());

        Assert::same($this->collect($source->refs(Path::create($this->tmp))), []);
        Assert::same($source->failures(), []);
    }

    public function yieldsResolvedRefsFromConfig(): void
    {
        $this->writeSkillsJson([
            'remote' => [
                ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v1.0.0'],
            ],
        ]);
        $registry = new HostAdapterRegistry(self::stubAdapter('github', static function (SourceEntry $entry) {
            return new RemoteDonorRef(
                url: 'https://api.example.com/' . $entry->identifier() . '/zipball/' . $entry->ref,
                ref: $entry->ref ?? 'main',
            );
        }));

        $source = new SkillsJsonDonorRefSource($registry);
        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        Assert::count($refs, 1);
        Assert::same($refs[0]->ref, 'v1.0.0');
        Assert::true(\str_contains($refs[0]->url, 'acme/skills/zipball/v1.0.0'));
        Assert::same($source->failures(), []);
    }

    public function cooldownCutoffIsPassedToTheAdapterUnlessExcluded(): void
    {
        $this->writeSkillsJson([
            'cooldown' => ['days' => 14, 'exclude' => ['team/*']],
            'sources' => [
                ['from' => 'github', 'package' => 'acme/skills'],
                ['from' => 'github', 'package' => 'team/skills'],
            ],
        ]);
        /** @var array<string, \DateTimeImmutable|null> $cutoffs */
        $cutoffs = [];
        $registry = new HostAdapterRegistry(self::stubAdapter(
            'github',
            static function (SourceEntry $entry, ?\DateTimeImmutable $notAfter) use (&$cutoffs): RemoteDonorRef {
                $cutoffs[$entry->identifier()] = $notAfter;
                return new RemoteDonorRef(url: 'https://api.example.com/' . $entry->identifier(), ref: 'main');
            },
        ));
        $now = new \DateTimeImmutable('2026-11-15T12:00:00Z');

        $source = new SkillsJsonDonorRefSource($registry, now: $now);
        $this->collect($source->refs(Path::create($this->tmp)));

        Assert::same($cutoffs['acme/skills']?->format(\DATE_ATOM), '2026-11-01T12:00:00+00:00');
        Assert::null($cutoffs['team/skills']);
    }

    public function inlineExtraIsReadWhenThereIsNoSkillsJson(): void
    {
        $extra = ['skills' => [
            'cooldown' => ['days' => 14],
            'sources' => [['from' => 'github', 'package' => 'acme/skills']],
        ]];
        /** @var list<\DateTimeImmutable|null> $cutoffs */
        $cutoffs = [];
        $registry = new HostAdapterRegistry(self::stubAdapter(
            'github',
            static function (SourceEntry $entry, ?\DateTimeImmutable $notAfter) use (&$cutoffs): RemoteDonorRef {
                $cutoffs[] = $notAfter;
                return new RemoteDonorRef(url: 'https://api.example.com/x', ref: 'main');
            },
        ));

        $source = new SkillsJsonDonorRefSource($registry, now: new \DateTimeImmutable('2026-11-15T12:00:00Z'), extra: $extra);
        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        Assert::count($refs, 1);
        Assert::same($cutoffs[0]?->format(\DATE_ATOM), '2026-11-01T12:00:00+00:00');
    }

    public function unknownAdapterProducesFailure(): void
    {
        // schema accepts `gitlab` but the registry only knows about
        // `github` in v1; runtime surfaces the gap as a failure and
        // skips the entry.
        $this->writeSkillsJson([
            'remote' => [
                ['from' => 'gitlab', 'package' => 'acme/skills'],
            ],
        ]);
        $source = new SkillsJsonDonorRefSource(new HostAdapterRegistry());

        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        Assert::same($refs, []);
        Assert::count($source->failures(), 1);
        Assert::true(\str_contains($source->failures()[0]->describe(), 'gitlab'));
    }

    public function resolveExceptionProducesFailure(): void
    {
        $this->writeSkillsJson([
            'remote' => [
                ['from' => 'github', 'package' => 'acme/missing', 'ref' => '^99.0'],
            ],
        ]);
        $registry = new HostAdapterRegistry(self::stubAdapter('github', static function (SourceEntry $entry) {
            throw new RemoteResolveException($entry, 'no matching tag');
        }));

        $source = new SkillsJsonDonorRefSource($registry);
        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        Assert::same($refs, []);
        Assert::count($source->failures(), 1);
        Assert::true(\str_contains($source->failures()[0]->describe(), 'no matching tag'));
    }

    public function oneFailureDoesNotBlockOtherEntries(): void
    {
        // First entry resolves cleanly; second throws; third is from
        // an unknown adapter. The source must yield the first ref and
        // accumulate two failures for the rest.
        $this->writeSkillsJson([
            'remote' => [
                ['from' => 'github', 'package' => 'acme/good', 'ref' => 'v1.0.0'],
                ['from' => 'github', 'package' => 'acme/bad', 'ref' => 'v1.0.0'],
                ['from' => 'gitlab', 'package' => 'acme/unknown'],
            ],
        ]);
        $registry = new HostAdapterRegistry(self::stubAdapter('github', static function (SourceEntry $entry) {
            if ($entry->package === 'acme/bad') {
                throw new RemoteResolveException($entry, 'simulated failure');
            }
            return new RemoteDonorRef('https://example.com/x.zip', $entry->ref ?? 'main');
        }));

        $source = new SkillsJsonDonorRefSource($registry);
        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        Assert::count($refs, 1);
        Assert::count($source->failures(), 2);
    }

    public function yieldedRefIsTaggedWithAdapterProvenance(): void
    {
        // For the `--from` CLI filter to work, the source carries each
        // entry's `from` value through to the yielded RemoteDonorRef
        // so that downstream VendorConfig.provenance can be set by the
        // SourceProvider. The adapter itself does not know its own id
        // at resolve time — the source backfills it here.
        $this->writeSkillsJson([
            'remote' => [
                ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v1.0.0'],
            ],
        ]);
        $registry = new HostAdapterRegistry(self::stubAdapter('github', static function (SourceEntry $entry) {
            // Note: stub returns provenance=null; the source must
            // re-tag with the entry's from-id.
            return new RemoteDonorRef('https://example.com/x', $entry->ref ?? 'main');
        }));

        $source = new SkillsJsonDonorRefSource($registry);
        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        Assert::count($refs, 1);
        Assert::same($refs[0]->provenance, 'github');
    }

    public function failuresResetBetweenIterations(): void
    {
        // Calling refs() twice must not concatenate failures from
        // the first call into the second.
        $this->writeSkillsJson([
            'remote' => [['from' => 'gitlab', 'package' => 'a/b']],
        ]);
        $source = new SkillsJsonDonorRefSource(new HostAdapterRegistry());

        $this->collect($source->refs(Path::create($this->tmp)));
        Assert::count($source->failures(), 1);

        $this->collect($source->refs(Path::create($this->tmp)));
        Assert::count($source->failures(), 1);
    }

    public function dirEntryResolvesRelativeToProjectRoot(): void
    {
        // A `dir` entry never touches the host-adapter registry; the
        // source resolves the path (relative → anchored at the project
        // root) into a DirDonorRef tagged with `dir` provenance.
        $this->writeSkillsJson([
            'sources' => [['from' => 'dir', 'path' => './skills']],
        ]);
        $source = new SkillsJsonDonorRefSource(new HostAdapterRegistry());

        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        Assert::count($refs, 1);
        $ref = $refs[0];
        Assert::instanceOf($ref, DirDonorRef::class);
        Assert::same((string) $ref->directory, (string) Path::create($this->tmp)->join('skills'));
        Assert::same($ref->spelling, './skills');
        Assert::same($ref->describe(), 'dir ./skills');
        Assert::same($ref->provenance, 'dir');
        Assert::same($source->failures(), []);
    }

    public function dirEntryHonoursAbsolutePathAsIs(): void
    {
        $absolute = $this->tmp . '/shared-skills';
        $this->writeSkillsJson([
            'sources' => [['from' => 'dir', 'path' => $absolute]],
        ]);
        $source = new SkillsJsonDonorRefSource(new HostAdapterRegistry());

        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        Assert::count($refs, 1);
        $ref = $refs[0];
        Assert::instanceOf($ref, DirDonorRef::class);
        // Absolute input is used verbatim, not re-anchored at the root.
        Assert::same((string) $ref->directory, (string) Path::create($absolute));
    }

    public function dirEntryDerivesPackageNameFromResolvedPath(): void
    {
        // No `package` override → the donor name is `<parent>/<basename>`
        // of the resolved path, lowercased.
        $this->writeSkillsJson([
            'sources' => [['from' => 'dir', 'path' => './Team/Skills']],
        ]);
        $source = new SkillsJsonDonorRefSource(new HostAdapterRegistry());

        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        $ref = $refs[0];
        Assert::instanceOf($ref, DirDonorRef::class);
        Assert::same($ref->packageHint, 'team/skills');
    }

    public function dirEntryPackageOverrideWinsOverDerivedName(): void
    {
        $this->writeSkillsJson([
            'sources' => [['from' => 'dir', 'path' => './skills', 'package' => 'myorg/shared']],
        ]);
        $source = new SkillsJsonDonorRefSource(new HostAdapterRegistry());

        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        $ref = $refs[0];
        Assert::instanceOf($ref, DirDonorRef::class);
        Assert::same($ref->packageHint, 'myorg/shared');
    }

    public function dirEntryAtFilesystemRootFallsBackToDirVendor(): void
    {
        // A path whose resolved parent is a filesystem root has no usable
        // vendor segment, so the name falls back to `dir/<basename>`.
        // The resolution is purely lexical — the directory need not exist.
        $rootChild = \DIRECTORY_SEPARATOR === '\\' ? 'Z:/loneskills' : '/loneskills';
        $this->writeSkillsJson([
            'sources' => [['from' => 'dir', 'path' => $rootChild]],
        ]);
        $source = new SkillsJsonDonorRefSource(new HostAdapterRegistry());

        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        $ref = $refs[0];
        Assert::instanceOf($ref, DirDonorRef::class);
        Assert::same($ref->packageHint, 'dir/loneskills');
    }

    public function dirEntryCarriesSkillAllowlist(): void
    {
        $this->writeSkillsJson([
            'sources' => [['from' => 'dir', 'path' => './skills', 'skills' => ['deploy']]],
        ]);
        $source = new SkillsJsonDonorRefSource(new HostAdapterRegistry());

        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        $ref = $refs[0];
        Assert::instanceOf($ref, DirDonorRef::class);
        Assert::same($ref->skillFilter, ['deploy']);
    }

    /**
     * @return list<RemoteDonorRef|DirDonorRef>
     */
    private function collect(iterable $refs): array
    {
        $out = [];
        foreach ($refs as $r) {
            $out[] = $r;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeSkillsJson(array $data): void
    {
        \file_put_contents(
            $this->tmp . '/skills.json',
            \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param non-empty-string $id
     * @param callable(SourceEntry, ?\DateTimeImmutable): RemoteDonorRef $resolver
     */
    private static function stubAdapter(string $id, callable $resolver): HostAdapter
    {
        return new class($id, $resolver) implements HostAdapter {
            /**
             * @param non-empty-string $id
             * @param callable(SourceEntry, ?\DateTimeImmutable): RemoteDonorRef $resolver
             */
            public function __construct(
                private readonly string $id,
                private readonly mixed $resolver,
            ) {}

            #[\Override]
            public function id(): string
            {
                return $this->id;
            }

            #[\Override]
            public function defaultHost(): string
            {
                return 'https://example.com';
            }

            #[\Override]
            public function parseAddInput(
                string $input,
                ?string $hostOverride = null,
                ?string $refOverride = null,
            ): ParsedAddInput {
                throw new \LogicException('not used in source tests');
            }

            #[\Override]
            public function resolve(SourceEntry $entry, ?\DateTimeImmutable $notAfter = null): RemoteDonorRef
            {
                /** @var callable(SourceEntry, ?\DateTimeImmutable): RemoteDonorRef $resolver */
                $resolver = $this->resolver;
                return $resolver($entry, $notAfter);
            }
        };
    }
}
