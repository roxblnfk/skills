<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use Composer\Composer;
use Composer\Package\PackageInterface;
use Internal\Path;
use LLM\Skills\Config\Exception\MalformedVendorConfig;
use LLM\Skills\Config\Mapper\VendorConfigMapper;
use LLM\Skills\Config\VendorConfig;

/**
 * Walks Composer's local repository and maps every package's
 * `extra.skills` block into {@see \LLM\Skills\Config\VendorConfig} rows —
 * one per declared source directory.
 *
 * Non-donors (no `extra.skills` block) are skipped silently. Donors with
 * a malformed block become entries in the returned `warnings` list — one
 * bad vendor never blocks the rest.
 *
 * No filesystem traversal happens here: discovery is purely metadata-
 * level. To then look inside each donor's source directory and find the
 * actual skill folders, hand the result to {@see SkillEnumerator}.
 */
final readonly class DonorDiscovery
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private VendorConfigMapper $vendorMapper = new VendorConfigMapper(),
        private SkillTreeScanner $scanner = new SkillTreeScanner(),
    ) {}

    public function discover(Composer $composer): DonorDiscoveryResult
    {
        $donors = [];
        $warnings = [];
        $malformed = [];
        $discoverable = [];
        $seenPaths = [];

        /** @var PackageInterface $package */
        foreach ($composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $extra = $package->getExtra();
            /** @var non-empty-string $name */
            $name = $package->getName();
            $installPath = $composer->getInstallationManager()->getInstallPath($package);

            // A dependency installed from a dev branch without a `branch-alias`
            // surfaces twice here: the real package and the synthetic
            // default-branch AliasPackage (`9999999-dev`) Composer pairs with
            // it, both resolving to one install path. Enumerating that path
            // once keeps a donor from being counted against itself as a
            // self-conflict. An empty path (metapackage) carries no skills, so
            // it never contends for deduplication.
            if ($installPath !== null && $installPath !== '') {
                if (isset($seenPaths[$installPath])) {
                    continue;
                }
                $seenPaths[$installPath] = true;
            }

            if (!VendorConfigMapper::declaresSkills($extra)) {
                if ($installPath === null) {
                    continue;
                }
                $packageRoot = Path::create($installPath);
                foreach ($this->discoverDonors($name, $packageRoot) as $donor) {
                    $discoverable[] = $donor;
                }
                continue;
            }

            if ($installPath === null) {
                $warnings[] = \sprintf('%s: install path unavailable', $name);
                continue;
            }

            try {
                foreach ($this->vendorMapper->fromExtra($name, Path::create($installPath), $extra) as $donor) {
                    $donors[] = $donor;
                }
            } catch (MalformedVendorConfig $e) {
                $warnings[] = $e->getMessage();
                // Strip the `Package "..." :` prefix the exception adds, so the
                // structured reason is the bare cause: "extra.skills.source must be ...".
                /** @var non-empty-string $reason */
                $reason = \preg_replace('/^Package "[^"]+": /', '', $e->getMessage()) ?? $e->getMessage();
                $malformed[] = new MalformedDonor(packageName: $e->packageName, reason: $reason);
            }
        }

        return new DonorDiscoveryResult(
            donors: $donors,
            warnings: $warnings,
            malformed: $malformed,
            discoverable: $discoverable,
        );
    }

    /**
     * Build the auto-discovered donor(s) for a package that does not declare
     * `extra.skills`. {@see SkillTreeScanner} finds the skill directories; here
     * they are grouped by their container so each conventional root (or catalog
     * category) becomes one donor row carrying its own `source`. The explicit
     * skill directories travel on {@see VendorConfig::$discoveredSkillDirs} so
     * the enumerator can honour catalog depth that the immediate-subdir model
     * cannot express.
     *
     * @param non-empty-string $packageName
     *
     * @return list<VendorConfig>
     */
    private function discoverDonors(string $packageName, Path $packageRoot): array
    {
        /** @var array<non-empty-string, list<Path>> $byContainer preserves first-seen order */
        $byContainer = [];
        foreach ($this->scanner->scan($packageRoot) as $skill) {
            $byContainer[$skill->container][] = $skill->dir;
        }

        $donors = [];
        foreach ($byContainer as $container => $dirs) {
            // A numeric container name (e.g. a category dir literally named
            // "2024") would have been coerced to an int array key — cast back.
            $source = (string) $container;
            /** @var non-empty-string $source */
            $donors[] = new VendorConfig(
                packageName: $packageName,
                packageRoot: $packageRoot,
                source: $source,
                discovered: true,
                discoveredSkillDirs: $dirs,
            );
        }

        return $donors;
    }
}
