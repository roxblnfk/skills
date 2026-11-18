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
     * `skills.json` or `skills.local.json`, and no project keys under
     * inline `extra.skills`. A
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

        // A personal `skills.local.json` acts as the main file when it is
        // alone, so its author has configured the project already.
        foreach ([ExternalProjectConfigLoader::FILE_NAME, ExternalProjectConfigLoader::LOCAL_FILE_NAME] as $file) {
            if (\is_file((string) $projectRoot->join($file))) {
                return false;
            }
        }

        /** @var mixed $skills */
        $skills = $rootPackage->getExtra()['skills'] ?? null;

        return !\is_array($skills) || ProjectConfigMigrator::presentProjectKeys($skills) === [];
    }
}
