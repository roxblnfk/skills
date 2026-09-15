<?php

declare(strict_types=1);

namespace LLM\Skills\Init;

use Composer\Package\RootPackageInterface;
use Internal\Path;
use LLM\Skills\Config\Mapper\ExternalProjectConfigLoader;
use LLM\Skills\Config\Mapper\ProjectConfigMigrator;
use LLM\Skills\Info;

/**
 * Decides whether the plugin should propose a configuration to the
 * project it was just installed into.
 *
 * Split out of {@see \LLM\Skills\Composer\SkillsPlugin} so the rule can
 * be exercised without a live Composer event: the rest of the offer is
 * the wizard, which has its own coverage.
 *
 * @internal
 */
final class SetupOffer
{
    /**
     * `true` for a project that has never been configured — no
     * `skills.json`, and no project keys under inline `extra.skills`. A
     * package declaring only the donor-side `source` is not configured as
     * a consumer, so it still gets the offer.
     */
    public static function isNeeded(Path $projectRoot, RootPackageInterface $rootPackage): bool
    {
        // Our own repository is the one project where the plugin is
        // installed but is not there to serve the root package.
        if ($rootPackage->getName() === Info::PACKAGE_NAME) {
            return false;
        }

        if (\is_file((string) $projectRoot->join(ExternalProjectConfigLoader::FILE_NAME))) {
            return false;
        }

        /** @var mixed $skills */
        $skills = $rootPackage->getExtra()['skills'] ?? null;

        return !\is_array($skills) || ProjectConfigMigrator::presentProjectKeys($skills) === [];
    }
}
