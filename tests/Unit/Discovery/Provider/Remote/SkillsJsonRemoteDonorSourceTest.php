<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider\Remote;

use Internal\Path;
use LLM\Skills\Config\RemoteEntry;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapter;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Remote\Adapter\ParsedAddInput;
use LLM\Skills\Discovery\Provider\Remote\Adapter\RemoteResolveException;
use LLM\Skills\Discovery\Provider\Remote\RemoteDonorRef;
use LLM\Skills\Discovery\Provider\Remote\SkillsJsonRemoteDonorSource;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(SkillsJsonRemoteDonorSource::class)]
final class SkillsJsonRemoteDonorSourceTest
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
        $source = new SkillsJsonRemoteDonorSource(new HostAdapterRegistry());

        Assert::same($this->collect($source->refs(Path::create($this->tmp))), []);
        Assert::same($source->warnings(), []);
    }

    public function emptyWhenSkillsJsonHasNoRemote(): void
    {
        $this->writeSkillsJson(['target' => '.agents/skills']);

        $source = new SkillsJsonRemoteDonorSource(new HostAdapterRegistry());

        Assert::same($this->collect($source->refs(Path::create($this->tmp))), []);
        Assert::same($source->warnings(), []);
    }

    public function yieldsResolvedRefsFromConfig(): void
    {
        $this->writeSkillsJson([
            'remote' => [
                ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v1.0.0'],
            ],
        ]);
        $registry = new HostAdapterRegistry(self::stubAdapter('github', static function (RemoteEntry $entry) {
            return new RemoteDonorRef(
                url: 'https://api.example.com/' . $entry->identifier() . '/zipball/' . $entry->ref,
                ref: $entry->ref ?? 'main',
            );
        }));

        $source = new SkillsJsonRemoteDonorSource($registry);
        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        Assert::count($refs, 1);
        Assert::same($refs[0]->ref, 'v1.0.0');
        Assert::true(\str_contains($refs[0]->url, 'acme/skills/zipball/v1.0.0'));
        Assert::same($source->warnings(), []);
    }

    public function unknownAdapterProducesWarning(): void
    {
        // schema accepts `gitlab` but the registry only knows about
        // `github` in v1; runtime surfaces the gap as a warning and
        // skips the entry.
        $this->writeSkillsJson([
            'remote' => [
                ['from' => 'gitlab', 'package' => 'acme/skills'],
            ],
        ]);
        $source = new SkillsJsonRemoteDonorSource(new HostAdapterRegistry());

        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        Assert::same($refs, []);
        Assert::count($source->warnings(), 1);
        Assert::true(\str_contains($source->warnings()[0], 'gitlab'));
    }

    public function resolveExceptionProducesWarning(): void
    {
        $this->writeSkillsJson([
            'remote' => [
                ['from' => 'github', 'package' => 'acme/missing', 'ref' => '^99.0'],
            ],
        ]);
        $registry = new HostAdapterRegistry(self::stubAdapter('github', static function (RemoteEntry $entry) {
            throw new RemoteResolveException($entry, 'no matching tag');
        }));

        $source = new SkillsJsonRemoteDonorSource($registry);
        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        Assert::same($refs, []);
        Assert::count($source->warnings(), 1);
        Assert::true(\str_contains($source->warnings()[0], 'no matching tag'));
    }

    public function oneFailureDoesNotBlockOtherEntries(): void
    {
        // First entry resolves cleanly; second throws; third is from
        // an unknown adapter. The source must yield the first ref and
        // accumulate two warnings for the rest.
        $this->writeSkillsJson([
            'remote' => [
                ['from' => 'github', 'package' => 'acme/good', 'ref' => 'v1.0.0'],
                ['from' => 'github', 'package' => 'acme/bad', 'ref' => 'v1.0.0'],
                ['from' => 'gitlab', 'package' => 'acme/unknown'],
            ],
        ]);
        $registry = new HostAdapterRegistry(self::stubAdapter('github', static function (RemoteEntry $entry) {
            if ($entry->package === 'acme/bad') {
                throw new RemoteResolveException($entry, 'simulated failure');
            }
            return new RemoteDonorRef('https://example.com/x.zip', $entry->ref ?? 'main');
        }));

        $source = new SkillsJsonRemoteDonorSource($registry);
        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        Assert::count($refs, 1);
        Assert::count($source->warnings(), 2);
    }

    public function yieldedRefIsTaggedWithAdapterProvenance(): void
    {
        // For the `--from` CLI filter to work, the source carries each
        // entry's `from` value through to the yielded RemoteDonorRef
        // so that downstream VendorConfig.provenance can be set by the
        // RemoteProvider. The adapter itself does not know its own id
        // at resolve time — the source backfills it here.
        $this->writeSkillsJson([
            'remote' => [
                ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v1.0.0'],
            ],
        ]);
        $registry = new HostAdapterRegistry(self::stubAdapter('github', static function (RemoteEntry $entry) {
            // Note: stub returns provenance=null; the source must
            // re-tag with the entry's from-id.
            return new RemoteDonorRef('https://example.com/x', $entry->ref ?? 'main');
        }));

        $source = new SkillsJsonRemoteDonorSource($registry);
        $refs = $this->collect($source->refs(Path::create($this->tmp)));

        Assert::count($refs, 1);
        Assert::same($refs[0]->provenance, 'github');
    }

    public function warningsResetBetweenIterations(): void
    {
        // Calling refs() twice must not concatenate warnings from
        // the first call into the second.
        $this->writeSkillsJson([
            'remote' => [['from' => 'gitlab', 'package' => 'a/b']],
        ]);
        $source = new SkillsJsonRemoteDonorSource(new HostAdapterRegistry());

        $this->collect($source->refs(Path::create($this->tmp)));
        Assert::count($source->warnings(), 1);

        $this->collect($source->refs(Path::create($this->tmp)));
        Assert::count($source->warnings(), 1);
    }

    /**
     * @return list<RemoteDonorRef>
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
     * @param callable(RemoteEntry): RemoteDonorRef $resolver
     */
    private static function stubAdapter(string $id, callable $resolver): HostAdapter
    {
        return new class($id, $resolver) implements HostAdapter {
            /**
             * @param non-empty-string $id
             * @param callable(RemoteEntry): RemoteDonorRef $resolver
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
            public function resolve(RemoteEntry $entry): RemoteDonorRef
            {
                /** @var callable(RemoteEntry): RemoteDonorRef $resolver */
                $resolver = $this->resolver;
                return $resolver($entry);
            }
        };
    }
}
