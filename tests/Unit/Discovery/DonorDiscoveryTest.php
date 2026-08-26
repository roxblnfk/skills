<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery;

use Composer\Composer;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\Installer\InstallerInterface;
use Composer\IO\NullIO;
use Composer\Package\AliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use LLM\Skills\Discovery\DonorDiscovery;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Unit coverage for {@see DonorDiscovery} walking Composer's local
 * repository.
 *
 * Composer creates two package objects for a dependency installed from a
 * dev branch without a `branch-alias`: the real `dev-main` package and an
 * {@see AliasPackage} carrying the default branch alias `9999999-dev`.
 * Both share one install path, so `getLocalRepository()->getPackages()`
 * returns the donor twice. Discovery must fold that pair back into a
 * single donor row — otherwise the same skill names are enumerated twice
 * and later flagged as a self-conflict.
 */
#[Test]
#[Covers(DonorDiscovery::class)]
final class DonorDiscoveryTest
{
    public function devBranchAliasDoesNotDoubleTheDonor(): void
    {
        $real = new CompletePackage('webman-tech/components-monorepo', 'dev-main', 'dev-main');
        $real->setType('library');
        $real->setExtra(['skills' => ['source' => 'skills']]);

        // The default-branch alias Composer synthesises for a dev-branch
        // dependency without an explicit `branch-alias`.
        $alias = new AliasPackage($real, '9999999-dev', '9999999-dev');

        $composer = $this->composerWith($real, $alias);

        $result = (new DonorDiscovery())->discover($composer);

        Assert::same(
            \count($result->donors),
            1,
            'a dev-branch package and its default-branch alias share one install path '
            . 'and must yield a single donor row',
        );
        Assert::same($result->donors[0]->packageName, 'webman-tech/components-monorepo');
        Assert::same($result->donors[0]->source, 'skills');
    }

    /**
     * Build a Composer whose local repository returns exactly the given
     * packages, with every package resolving to `<vendor>/<name>` under a
     * fixed install root. No real Composer bootstrap or filesystem access.
     */
    private function composerWith(PackageInterface ...$packages): Composer
    {
        $io = new NullIO();
        $config = new Config(false, \sys_get_temp_dir());

        $local = new InstalledArrayRepository();
        foreach ($packages as $package) {
            $local->addPackage($package);
        }

        $http = new HttpDownloader($io, $config);
        $repositoryManager = new RepositoryManager($io, $config, $http);
        $repositoryManager->setLocalRepository($local);

        $installationManager = new InstallationManager(new Loop($http), $io);
        $installationManager->addInstaller($this->pathStubInstaller());

        $composer = new Composer();
        $composer->setConfig($config);
        $composer->setRepositoryManager($repositoryManager);
        $composer->setInstallationManager($installationManager);

        return $composer;
    }

    /**
     * A no-op installer that only answers {@see InstallerInterface::getInstallPath()},
     * mapping every package to `<vendor-dir>/<name>`. That is all discovery
     * needs, and it keeps a real installer's filesystem work out of the test.
     */
    private function pathStubInstaller(): InstallerInterface
    {
        return new class implements InstallerInterface {
            public function supports(string $packageType): bool
            {
                return true;
            }

            public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package): bool
            {
                return true;
            }

            public function download(PackageInterface $package, ?PackageInterface $prevPackage = null)
            {
                return null;
            }

            public function prepare(string $type, PackageInterface $package, ?PackageInterface $prevPackage = null)
            {
                return null;
            }

            public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
            {
                return null;
            }

            public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
            {
                return null;
            }

            public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
            {
                return null;
            }

            public function cleanup(string $type, PackageInterface $package, ?PackageInterface $prevPackage = null)
            {
                return null;
            }

            public function getInstallPath(PackageInterface $package): string
            {
                return \sys_get_temp_dir() . '/vendor/' . $package->getName();
            }
        };
    }
}
