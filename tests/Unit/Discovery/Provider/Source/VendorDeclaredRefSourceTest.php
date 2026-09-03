<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider\Source;

use Composer\Composer;
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Package\AliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Util\HttpDownloader;
use Internal\Path;
use LLM\Skills\Config\SourceEntry;
use LLM\Skills\Discovery\Provider\Source\Adapter\HostAdapter;
use LLM\Skills\Discovery\Provider\Source\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Source\Adapter\ParsedAddInput;
use LLM\Skills\Discovery\Provider\Source\Adapter\RemoteResolveException;
use LLM\Skills\Discovery\Provider\Source\RemoteDonorRef;
use LLM\Skills\Discovery\Provider\Source\VendorDeclaredRefSource;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(VendorDeclaredRefSource::class)]
final class VendorDeclaredRefSourceTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-vendor-source-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    // ── activation ──

    public function inactiveWithoutComposer(): void
    {
        $source = new VendorDeclaredRefSource(null, new HostAdapterRegistry());

        Assert::false($source->hasRefs($this->root()));
        Assert::same($this->collect($source->refs($this->root())), []);
        Assert::same($source->failures(), []);
    }

    public function inactiveWhenNoPackageDeclaresSources(): void
    {
        $composer = $this->composerWith(
            self::package('acme/local-only', '1.0.0', ['skills' => ['source' => 'skills']]),
        );
        $source = new VendorDeclaredRefSource($composer, new HostAdapterRegistry());

        Assert::false($source->hasRefs($this->root()));
        Assert::same($this->collect($source->refs($this->root())), []);
    }

    public function vendorSourcesToggleDisablesTheSource(): void
    {
        \file_put_contents(
            $this->tmp . '/skills.json',
            \json_encode(['vendor-sources' => false], \JSON_THROW_ON_ERROR),
        );
        $composer = $this->composerWith(self::declaringPackage('acme/foo', '1.0.0', [
            ['from' => 'github', 'package' => 'acme/skills'],
        ]));
        $source = new VendorDeclaredRefSource($composer, $this->echoRegistry());

        Assert::false($source->hasRefs($this->root()));
        Assert::same($this->collect($source->refs($this->root())), []);
        Assert::same($source->failures(), []);
    }

    // ── happy path ──

    public function yieldsDeclaredRefWithTrustAttribution(): void
    {
        $composer = $this->composerWith(self::declaringPackage('acme/foo', '1.0.0', [
            ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v1.0.0', 'skills' => ['deploy']],
        ]));
        $source = new VendorDeclaredRefSource($composer, $this->echoRegistry());

        Assert::true($source->hasRefs($this->root()));
        $refs = $this->collect($source->refs($this->root()));

        Assert::count($refs, 1);
        Assert::same($refs[0]->declaredBy, ['acme/foo']);
        Assert::same($refs[0]->provenance, 'github');
        Assert::same($refs[0]->packageHint, 'acme/skills');
        Assert::same($refs[0]->skillFilter, ['deploy']);
        Assert::same($refs[0]->ref, 'v1.0.0');
        Assert::same($refs[0]->label(), 'acme/foo → github:acme/skills');
        Assert::same($source->failures(), []);
    }

    // ── self.version binding ──

    public function selfVersionOnReleasedVersionBindsExactConstraint(): void
    {
        $seen = [];
        $composer = $this->composerWith(self::declaringPackage('acme/foo', '1.4.2', [
            ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'self.version'],
        ]));
        $source = new VendorDeclaredRefSource($composer, $this->echoRegistry($seen));

        $refs = $this->collect($source->refs($this->root()));

        Assert::count($refs, 1);
        Assert::same($seen[0]->ref, '=1.4.2');
    }

    public function selfVersionOnDevBranchBindsBranchName(): void
    {
        $seen = [];
        $composer = $this->composerWith(self::declaringPackage('acme/foo', 'dev-main', [
            ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'self.version'],
        ]));
        $source = new VendorDeclaredRefSource($composer, $this->echoRegistry($seen));

        $this->collect($source->refs($this->root()));

        Assert::same($seen[0]->ref, 'main');
    }

    public function selfVersionOnNumericBranchBindsBranchName(): void
    {
        $seen = [];
        $composer = $this->composerWith(self::declaringPackage('acme/foo', '1.x-dev', [
            ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'self.version'],
        ]));
        $source = new VendorDeclaredRefSource($composer, $this->echoRegistry($seen));

        $this->collect($source->refs($this->root()));

        Assert::same($seen[0]->ref, '1.x');
    }

    public function selfVersionUsesTheRealPackageBehindABranchAlias(): void
    {
        // Composer pairs a dev-branch dependency with a synthetic
        // `9999999-dev` alias; binding self.version to the alias would
        // produce a nonsense ref. The source must unwrap to the real
        // package (and not yield the declaration twice).
        $real = self::declaringPackage('acme/foo', 'dev-main', [
            ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'self.version'],
        ]);
        $alias = new AliasPackage($real, '9999999-dev', '9999999-dev');

        $seen = [];
        $composer = $this->composerWith($alias, $real);
        $source = new VendorDeclaredRefSource($composer, $this->echoRegistry($seen));

        $refs = $this->collect($source->refs($this->root()));

        Assert::count($refs, 1);
        Assert::same($seen[0]->ref, 'main');
    }

    public function selfVersionOnUnusableVersionProducesFailure(): void
    {
        $composer = $this->composerWith(self::declaringPackage('acme/foo', 'dev-', [
            ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'self.version'],
        ]));
        $source = new VendorDeclaredRefSource($composer, $this->echoRegistry());

        $refs = $this->collect($source->refs($this->root()));

        Assert::same($refs, []);
        Assert::count($source->failures(), 1);
        Assert::string($source->failures()[0]->describe())
            ->contains('acme/foo → github:acme/skills')
            ->contains('self.version');
    }

    public function resolveSelfVersionTable(): void
    {
        Assert::same(VendorDeclaredRefSource::resolveSelfVersion('1.4.2'), '=1.4.2');
        Assert::same(VendorDeclaredRefSource::resolveSelfVersion('v1.4.2'), '=v1.4.2');
        Assert::same(VendorDeclaredRefSource::resolveSelfVersion('1.0.0-beta1'), '=1.0.0-beta1');
        Assert::same(VendorDeclaredRefSource::resolveSelfVersion('dev-main'), 'main');
        Assert::same(VendorDeclaredRefSource::resolveSelfVersion('dev-feature/x'), 'feature/x');
        Assert::same(VendorDeclaredRefSource::resolveSelfVersion('1.x-dev'), '1.x');
        Assert::same(VendorDeclaredRefSource::resolveSelfVersion('2.0.x-dev'), '2.0.x');
        Assert::same(VendorDeclaredRefSource::resolveSelfVersion('dev-'), null);
        Assert::same(VendorDeclaredRefSource::resolveSelfVersion('-dev'), null);
        Assert::same(VendorDeclaredRefSource::resolveSelfVersion(''), null);
    }

    // ── cross-package dedup & allowlist union ──

    public function sharedBundleIsFetchedOnceWithUnionedAllowlists(): void
    {
        $composer = $this->composerWith(
            self::declaringPackage('acme/one', '1.0.0', [
                ['from' => 'github', 'package' => 'acme/bundle', 'ref' => 'v1.0.0', 'skills' => ['deploy']],
            ]),
            self::declaringPackage('acme/two', '1.0.0', [
                ['from' => 'github', 'package' => 'acme/bundle', 'ref' => 'v1.0.0', 'skills' => ['review', 'deploy']],
            ]),
        );
        $source = new VendorDeclaredRefSource($composer, $this->echoRegistry());

        $refs = $this->collect($source->refs($this->root()));

        Assert::count($refs, 1);
        Assert::same($refs[0]->skillFilter, ['deploy', 'review']);
        Assert::same($refs[0]->declaredBy, ['acme/one', 'acme/two']);
    }

    public function sharedBundleFailureNamesEveryDeclarer(): void
    {
        // A resolve failure on a merged entry must point at both
        // composer.json files it lives in, not just the first one seen.
        $registry = new HostAdapterRegistry(self::stubAdapter('github', static function (SourceEntry $entry): RemoteDonorRef {
            throw new RemoteResolveException($entry, 'no matching tag');
        }));
        $composer = $this->composerWith(
            self::declaringPackage('acme/one', '1.0.0', [
                ['from' => 'github', 'package' => 'acme/bundle', 'ref' => '^9.0'],
            ]),
            self::declaringPackage('acme/two', '1.0.0', [
                ['from' => 'github', 'package' => 'acme/bundle', 'ref' => '^9.0'],
            ]),
        );
        $source = new VendorDeclaredRefSource($composer, $registry);

        $this->collect($source->refs($this->root()));

        Assert::count($source->failures(), 1);
        Assert::string($source->failures()[0]->describe())
            ->contains('acme/one, acme/two → github:acme/bundle');
    }

    public function absentAllowlistAbsorbsTheUnion(): void
    {
        // "No filter" means every skill; a sibling's narrower list must
        // not un-widen it, in either declaration order.
        $composer = $this->composerWith(
            self::declaringPackage('acme/one', '1.0.0', [
                ['from' => 'github', 'package' => 'acme/bundle', 'ref' => 'v1.0.0', 'skills' => ['deploy']],
            ]),
            self::declaringPackage('acme/two', '1.0.0', [
                ['from' => 'github', 'package' => 'acme/bundle', 'ref' => 'v1.0.0'],
            ]),
        );
        $source = new VendorDeclaredRefSource($composer, $this->echoRegistry());

        $refs = $this->collect($source->refs($this->root()));

        Assert::count($refs, 1);
        Assert::same($refs[0]->skillFilter, null);
    }

    public function differentRefsForTheSameBundleStaySeparate(): void
    {
        $composer = $this->composerWith(
            self::declaringPackage('acme/one', '1.0.0', [
                ['from' => 'github', 'package' => 'acme/bundle', 'ref' => 'v1.0.0'],
            ]),
            self::declaringPackage('acme/two', '1.0.0', [
                ['from' => 'github', 'package' => 'acme/bundle', 'ref' => 'v2.0.0'],
            ]),
        );
        $source = new VendorDeclaredRefSource($composer, $this->echoRegistry());

        $refs = $this->collect($source->refs($this->root()));

        Assert::count($refs, 2);
        Assert::same($refs[0]->ref, 'v1.0.0');
        Assert::same($refs[1]->ref, 'v2.0.0');
    }

    // ── failure isolation ──

    public function malformedSourcesFailsThePackageWithoutBlockingOthers(): void
    {
        $composer = $this->composerWith(
            self::declaringPackage('acme/broken', '1.0.0', [
                ['from' => 'nonsense', 'package' => 'acme/bundle'],
            ]),
            self::declaringPackage('acme/good', '1.0.0', [
                ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v1.0.0'],
            ]),
        );
        $source = new VendorDeclaredRefSource($composer, $this->echoRegistry());

        $refs = $this->collect($source->refs($this->root()));

        Assert::count($refs, 1);
        Assert::same($refs[0]->declaredBy, ['acme/good']);
        Assert::count($source->failures(), 1);
        Assert::string($source->failures()[0]->describe())
            ->contains('acme/broken')
            ->contains('invalid extra.skills.sources');
    }

    public function dirEntryIsRejectedWithATailoredMessage(): void
    {
        $composer = $this->composerWith(self::declaringPackage('acme/foo', '1.0.0', [
            ['from' => 'dir', 'path' => './skills'],
        ]));
        $source = new VendorDeclaredRefSource($composer, $this->echoRegistry());

        $refs = $this->collect($source->refs($this->root()));

        Assert::same($refs, []);
        Assert::count($source->failures(), 1);
        Assert::string($source->failures()[0]->describe())
            ->contains('not allowed in a vendor package')
            ->contains('extra.skills.source');
    }

    public function unknownAdapterProducesLabeledFailure(): void
    {
        $composer = $this->composerWith(self::declaringPackage('acme/foo', '1.0.0', [
            ['from' => 'gitlab', 'package' => 'acme/skills'],
        ]));
        $source = new VendorDeclaredRefSource($composer, new HostAdapterRegistry());

        $refs = $this->collect($source->refs($this->root()));

        Assert::same($refs, []);
        Assert::count($source->failures(), 1);
        Assert::string($source->failures()[0]->describe())
            ->contains('acme/foo → gitlab:acme/skills')
            ->contains('unknown source adapter');
    }

    public function resolveExceptionProducesLabeledFailure(): void
    {
        $registry = new HostAdapterRegistry(self::stubAdapter('github', static function (SourceEntry $entry): RemoteDonorRef {
            throw new RemoteResolveException($entry, 'no matching tag');
        }));
        $composer = $this->composerWith(self::declaringPackage('acme/foo', '1.0.0', [
            ['from' => 'github', 'package' => 'acme/skills', 'ref' => '^9.0'],
        ]));
        $source = new VendorDeclaredRefSource($composer, $registry);

        $refs = $this->collect($source->refs($this->root()));

        Assert::same($refs, []);
        Assert::count($source->failures(), 1);
        Assert::string($source->failures()[0]->describe())
            ->contains('acme/foo → github:acme/skills')
            ->contains('no matching tag');
    }

    public function failuresResetBetweenIterations(): void
    {
        $composer = $this->composerWith(self::declaringPackage('acme/foo', '1.0.0', [
            ['from' => 'gitlab', 'package' => 'acme/skills'],
        ]));
        $source = new VendorDeclaredRefSource($composer, new HostAdapterRegistry());

        $this->collect($source->refs($this->root()));
        Assert::count($source->failures(), 1);

        $this->collect($source->refs($this->root()));
        Assert::count($source->failures(), 1);
    }

    // ── helpers ──

    private function root(): Path
    {
        return Path::create($this->tmp);
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
     * @param non-empty-string $name
     * @param non-empty-string $prettyVersion
     * @param list<array<string, mixed>> $sources
     */
    private static function declaringPackage(string $name, string $prettyVersion, array $sources): CompletePackage
    {
        return self::package($name, $prettyVersion, ['skills' => ['sources' => $sources]]);
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $prettyVersion
     * @param array<string, mixed> $extra
     */
    private static function package(string $name, string $prettyVersion, array $extra): CompletePackage
    {
        // The ref source reads pretty versions only, so the fixtures
        // use the same string for both version fields.
        $package = new CompletePackage($name, $prettyVersion, $prettyVersion);
        $package->setType('library');
        $package->setExtra($extra);

        return $package;
    }

    /**
     * Build a Composer whose local repository returns exactly the given
     * packages. No real Composer bootstrap or filesystem access; the
     * ref source only walks repository metadata.
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
     * Registry with a `github` adapter that echoes the entry back as a
     * ref (`url` derived from identifier + ref) and optionally records
     * every entry it was asked to resolve.
     *
     * @param list<SourceEntry> $seen populated by reference as entries resolve
     */
    private function echoRegistry(array &$seen = []): HostAdapterRegistry
    {
        return new HostAdapterRegistry(self::stubAdapter(
            'github',
            static function (SourceEntry $entry) use (&$seen): RemoteDonorRef {
                $seen[] = $entry;
                return new RemoteDonorRef(
                    url: 'https://api.example.com/' . $entry->identifier() . '/zipball/' . ($entry->ref ?? 'main'),
                    ref: $entry->ref ?? 'main',
                );
            },
        ));
    }

    /**
     * @param non-empty-string $id
     * @param callable(SourceEntry): RemoteDonorRef $resolver
     */
    private static function stubAdapter(string $id, callable $resolver): HostAdapter
    {
        return new class($id, $resolver) implements HostAdapter {
            /**
             * @param non-empty-string $id
             * @param callable(SourceEntry): RemoteDonorRef $resolver
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
            public function resolve(SourceEntry $entry): RemoteDonorRef
            {
                /** @var callable(SourceEntry): RemoteDonorRef $resolver */
                $resolver = $this->resolver;
                return $resolver($entry);
            }
        };
    }
}
