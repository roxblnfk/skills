<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use Composer\Composer;
use Composer\Package\PackageInterface;
use Internal\Path;
use LLM\Skills\Config\Exception\MalformedVendorConfig;
use LLM\Skills\Config\Mapper\VendorConfigMapper;

/**
 * Walks Composer's local repository and maps every package's
 * `extra.skills` block into a {@see \LLM\Skills\Config\VendorConfig}.
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
    ) {}

    public function discover(Composer $composer): DonorDiscoveryResult
    {
        $donors = [];
        $warnings = [];
        $malformed = [];

        /** @var PackageInterface $package */
        foreach ($composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $extra = $package->getExtra();
            if (!VendorConfigMapper::declaresSkills($extra)) {
                continue;
            }

            $installPath = $composer->getInstallationManager()->getInstallPath($package);
            if ($installPath === null) {
                $warnings[] = \sprintf(
                    '%s: install path unavailable',
                    $package->getName(),
                );
                continue;
            }

            /** @var non-empty-string $name */
            $name = $package->getName();

            try {
                $donors[] = $this->vendorMapper->fromExtra($name, Path::create($installPath), $extra);
            } catch (MalformedVendorConfig $e) {
                $warnings[] = $e->getMessage();
                // Strip the `Package "..." :` prefix the exception adds, so the
                // structured reason is the bare cause: "extra.skills.source must be ...".
                /** @var non-empty-string $reason */
                $reason = \preg_replace('/^Package "[^"]+": /', '', $e->getMessage()) ?? $e->getMessage();
                $malformed[] = new MalformedDonor(packageName: $e->packageName, reason: $reason);
            }
        }

        return new DonorDiscoveryResult(donors: $donors, warnings: $warnings, malformed: $malformed);
    }
}
