<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Sync;

use Composer\IO\BufferIO;
use Internal\Path;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Discovery\DonorDiscoveryResult;
use LLM\Skills\Discovery\Provider\DonorProvider;
use LLM\Skills\Sync\SyncRunner;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Console\Command\Command;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * {@see SyncRunner} driven by a stub {@see DonorProvider}, for donor sets the
 * sandbox cannot produce offline.
 */
#[Test]
final class SyncRunnerTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-runner-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function aFromScopedRunConflictsWithASiblingRowOfTheSamePackage(): void
    {
        // A `github` entry and a `dir` clone of the same pack resolve to one
        // package name; `--from=github` keeps only the first.
        $provider = $this->providerOf([
            $this->donor('github', 'remote'),
            $this->donor('dir', 'clone'),
        ]);
        \mkdir($this->tmp . '/.agents/skills/pdf', 0o777, true);
        \file_put_contents($this->tmp . '/.agents/skills/pdf/SKILL.md', 'installed from the clone');

        $io = new BufferIO();
        $code = (new SyncRunner())->run(
            Path::create($this->tmp),
            $provider,
            null,
            $io,
            new SyncOptions(packageFilters: [], extraTrusted: [], targetOverride: null, interactive: false, fromFilter: 'github'),
        );

        Assert::same($code, Command::FAILURE, $io->getOutput());
        Assert::true(\str_contains($io->getOutput(), '[conflict] skill "pdf" declared by: acme/tools, acme/tools'), $io->getOutput());
        Assert::same((string) \file_get_contents($this->tmp . '/.agents/skills/pdf/SKILL.md'), 'installed from the clone');
    }

    /**
     * @param non-empty-string $provenance
     * @param non-empty-string $label
     */
    private function donor(string $provenance, string $label): VendorConfig
    {
        $root = $this->tmp . '/' . $label;
        \mkdir($root . '/skills/pdf', 0o777, true);
        \file_put_contents($root . '/skills/pdf/SKILL.md', "---\nname: pdf\n---\nfrom {$label}");

        return new VendorConfig(
            packageName: 'acme/tools',
            packageRoot: Path::create($root),
            source: 'skills',
            provenance: $provenance,
            implicitTrust: true,
        );
    }

    /**
     * @param list<VendorConfig> $donors
     */
    private function providerOf(array $donors): DonorProvider
    {
        return new class($donors) implements DonorProvider {
            /**
             * @param list<VendorConfig> $donors
             */
            public function __construct(
                private readonly array $donors,
            ) {}

            public function isActive(Path $projectRoot): bool
            {
                return true;
            }

            public function discover(Path $projectRoot): DonorDiscoveryResult
            {
                return new DonorDiscoveryResult(donors: $this->donors, warnings: []);
            }

            public function directDependencies(Path $projectRoot): array
            {
                return [];
            }
        };
    }
}
