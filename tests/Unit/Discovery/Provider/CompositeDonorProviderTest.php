<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider;

use Internal\Path;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Discovery\DonorDiscoveryResult;
use LLM\Skills\Discovery\MalformedDonor;
use LLM\Skills\Discovery\Provider\CompositeDonorProvider;
use LLM\Skills\Discovery\Provider\DonorProvider;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CompositeDonorProvider::class)]
final class CompositeDonorProviderTest
{
    public function inactiveWhenNoChildren(): void
    {
        $composite = new CompositeDonorProvider();

        Assert::false($composite->isActive(self::root()));
        $result = $composite->discover(self::root());
        Assert::same($result->donors, []);
        Assert::same($result->warnings, []);
    }

    public function activeIfAnyChildIsActive(): void
    {
        $inactive = self::provider(active: false);
        $active = self::provider(active: true);

        $composite = new CompositeDonorProvider($inactive, $active);

        Assert::true($composite->isActive(self::root()));
    }

    public function inactiveChildrenAreSkippedInDiscover(): void
    {
        // Saves a discover() call on a child that has nothing to
        // offer — the runner's empty-result path would otherwise add
        // pointless allocations on every sync.
        $inactive = self::provider(
            active: false,
            discover: new DonorDiscoveryResult(
                donors: [self::donor('acme/should-not-appear')],
                warnings: ['should not appear'],
            ),
        );
        $active = self::provider(
            active: true,
            discover: new DonorDiscoveryResult(
                donors: [self::donor('acme/visible')],
                warnings: [],
            ),
        );

        $composite = new CompositeDonorProvider($inactive, $active);
        $result = $composite->discover(self::root());

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->packageName, 'acme/visible');
        Assert::same($result->warnings, []);
    }

    public function concatenatesDonorsWarningsMalformedDiscoverable(): void
    {
        $a = self::provider(
            active: true,
            discover: new DonorDiscoveryResult(
                donors: [self::donor('acme/a')],
                warnings: ['warn-a'],
                malformed: [new MalformedDonor('acme/bad-a', 'reason-a')],
                discoverable: [self::donor('acme/disco-a', discovered: true)],
            ),
        );
        $b = self::provider(
            active: true,
            discover: new DonorDiscoveryResult(
                donors: [self::donor('acme/b')],
                warnings: ['warn-b'],
                malformed: [new MalformedDonor('acme/bad-b', 'reason-b')],
                discoverable: [self::donor('acme/disco-b', discovered: true)],
            ),
        );

        $result = (new CompositeDonorProvider($a, $b))->discover(self::root());

        Assert::same(
            \array_map(static fn($d) => $d->packageName, $result->donors),
            ['acme/a', 'acme/b'],
        );
        Assert::same($result->warnings, ['warn-a', 'warn-b']);
        Assert::same(
            \array_map(static fn($m) => $m->packageName, $result->malformed),
            ['acme/bad-a', 'acme/bad-b'],
        );
        Assert::count($result->discoverable, 2);
    }

    public function laterChildWinsOnDuplicatePackageName(): void
    {
        // Spec §6.5: remote wins on collisions with local. The composite
        // does not know "local vs remote" — the entrypoint orders
        // children so that remote is last; the composite's only rule is
        // "later wins". Older entries become `-v` warnings.
        $local = self::provider(
            active: true,
            discover: new DonorDiscoveryResult(
                donors: [self::donor('acme/skills', source: 'local-source')],
                warnings: [],
            ),
        );
        $remote = self::provider(
            active: true,
            discover: new DonorDiscoveryResult(
                donors: [self::donor('acme/skills', source: 'remote-source')],
                warnings: [],
            ),
        );

        $result = (new CompositeDonorProvider($local, $remote))->discover(self::root());

        Assert::count($result->donors, 1);
        Assert::same($result->donors[0]->source, 'remote-source');
        Assert::count($result->warnings, 1);
        Assert::true(\str_contains($result->warnings[0], 'acme/skills'));
    }

    public function directDependenciesAreUnionedAndDeduped(): void
    {
        $a = self::provider(
            active: true,
            directDeps: ['acme/x', 'acme/y'],
        );
        $b = self::provider(
            active: true,
            directDeps: ['acme/y', 'acme/z'], // y duplicates
        );

        $deps = (new CompositeDonorProvider($a, $b))->directDependencies(self::root());

        Assert::same($deps, ['acme/x', 'acme/y', 'acme/z']);
    }

    private static function root(): Path
    {
        return Path::create(\sys_get_temp_dir());
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $source
     */
    private static function donor(string $name, string $source = 'skills', bool $discovered = false): VendorConfig
    {
        return new VendorConfig(
            packageName: $name,
            packageRoot: self::root(),
            source: $source,
            discovered: $discovered,
        );
    }

    /**
     * @param list<non-empty-string> $directDeps
     */
    private static function provider(
        bool $active,
        ?DonorDiscoveryResult $discover = null,
        array $directDeps = [],
    ): DonorProvider {
        $result = $discover ?? new DonorDiscoveryResult(donors: [], warnings: []);
        return new class($active, $result, $directDeps) implements DonorProvider {
            /**
             * @param list<non-empty-string> $directDeps
             */
            public function __construct(
                private readonly bool $active,
                private readonly DonorDiscoveryResult $result,
                private readonly array $directDeps,
            ) {}

            #[\Override]
            public function isActive(Path $projectRoot): bool
            {
                return $this->active;
            }

            #[\Override]
            public function discover(Path $projectRoot): DonorDiscoveryResult
            {
                return $this->result;
            }

            #[\Override]
            public function directDependencies(Path $projectRoot): array
            {
                return $this->directDeps;
            }
        };
    }
}
