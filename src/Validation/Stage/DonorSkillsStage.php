<?php

declare(strict_types=1);

namespace LLM\Skills\Validation\Stage;

use LLM\Skills\Config\Mapper\VendorConfigMapper;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\TrustedVendorRegistry;
use LLM\Skills\Discovery\DiscoveryResolver;
use LLM\Skills\Discovery\Provider\DonorProviderBuilder;
use LLM\Skills\Discovery\Provider\ProviderId;
use LLM\Skills\Discovery\SkillEnumerator;
use LLM\Skills\Discovery\SourceFailure;
use LLM\Skills\Pipeline\ProjectContext;
use LLM\Skills\Pipeline\Stage;
use LLM\Skills\Sync\SkillConflict;
use LLM\Skills\Sync\SyncEngine;
use LLM\Skills\Sync\SyncPlanner;
use LLM\Skills\Validation\FindingCollector;
use LLM\Skills\Validation\PathDisplay;
use LLM\Skills\Validation\SkillValidator;

/**
 * Validates what the next `skills:update` would install, without copying it: donors are
 * discovered, trusted and enumerated as the sync does them, and their skills checked where
 * they lie.
 *
 * What breaks the sync and is fixed in the project's configuration is an error: two donors
 * shipping one skill name, and an entry of the project's `sources[]` that cannot be read.
 * A third-party donor's skills, and the sources an installed package declares, are someone
 * else's to fix, so their findings are warnings at most; skills of a `dir` source are the
 * project's own and keep their errors.
 *
 * Installed packages and `dir` entries are always read; the donors behind the network only
 * with `--fetch`. Publishes the names of the skills found, so the installed copies of them
 * are not checked twice.
 *
 * @internal
 */
final class DonorSkillsStage implements Stage
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly DonorProviderBuilder $providers = new DonorProviderBuilder(),
        private readonly SyncPlanner $planner = new SyncPlanner(),
        private readonly SkillEnumerator $enumerator = new SkillEnumerator(),
        private readonly SyncEngine $engine = new SyncEngine(),
        private readonly DiscoveryResolver $discoveryResolver = new DiscoveryResolver(),
    ) {}

    #[\Override]
    public function run(ProjectContext $context, FindingCollector $findings): void
    {
        $config = $context->config;
        $target = $context->target;
        if ($config === null || $target === null) {
            return;
        }

        $context->allDonorsRead = $context->fetch || !self::hasRemoteDonors($context);
        if (!$context->allDonorsRead) {
            $findings->info($context->configFile, null, 'donors-not-fetched', 'donors that need the network (remote sources[] entries, sources installed packages declare) were not fetched, so neither their skills nor clashes with them are checked; run with --fetch to include them');
        }

        $provider = $this->providers->build($context->projectRoot, $context->composer, $context->extra, remote: $context->fetch);
        if (!$provider->isActive($context->projectRoot)) {
            return;
        }

        $options = SyncOptions::default();
        $discovery = $provider->discover($context->projectRoot, $target);
        foreach ($discovery->failures as $failure) {
            $failure->declaredByPackage
                ? $findings->warn($context->configFile, null, 'source-failed', self::describeFailure($failure) . '; it is declared by an installed package, so report it there')
                : $findings->error($context->configFile, null, 'source-failed', self::describeFailure($failure) . '; fix the entry or remove it');
        }
        foreach ($discovery->malformed as $malformed) {
            $findings->warn(self::packageFile($context, $malformed->packageName), null, 'donor-malformed', 'extra.skills of this package is malformed, so skills:update skips it: ' . $malformed->reason . '; report it to the package');
        }

        $donors = [
            ...$discovery->donors,
            ...$this->discoveryResolver->resolve($discovery->discoverable, $config->discovery, $options)->included,
        ];
        $plan = $this->planner->plan(
            $donors,
            $config,
            $options,
            (new TrustedVendorRegistry())->loadForProvider(ProviderId::COMPOSER),
            $context->projectRoot,
            $provider->directDependencies($context->projectRoot),
        );

        $enumeration = $this->enumerator->enumerate($plan->approvedDonors, $plan->target);
        foreach ($enumeration->skipped as $notice) {
            $findings->warn(self::packageFile($context, $notice->packageName), null, 'donor-skill-skipped', $notice->message);
        }
        foreach ($enumeration->dropReasons as $package => $reasons) {
            foreach ($reasons as $reason) {
                $findings->warn(self::packageFile($context, $package), null, 'donor-dropped', 'skills:update installs nothing from this donor: ' . $reason);
            }
        }

        foreach ($this->engine->sync($enumeration->skills, $plan->target, dryRun: true)->conflicts as $conflict) {
            $findings->error($context->configFile, null, 'skill-conflict', \sprintf(
                'skill "%s" comes from several trusted donors (%s), so skills:update aborts before writing anything; narrow one donor with a skills allowlist, or stop trusting one of them',
                $conflict->name,
                \implode(', ', $conflict->packages),
            ));
        }

        // A `dir` source is the project's own directory, so its skills keep their errors.
        $ownDonors = [];
        foreach ($plan->approvedDonors as $donor) {
            $donor->provenance === ProviderId::DIR and $ownDonors[$donor->packageName] = true;
        }
        $theirs = new FindingCollector();
        $ownValidator = new SkillValidator($findings, $context->paths, $context->validatedSkills);
        $theirValidator = new SkillValidator($theirs, $context->paths, $context->validatedSkills);
        foreach ($enumeration->skills as $skill) {
            $context->donorSkillKeys[SkillConflict::collisionKey($skill->name)] = true;
            (isset($ownDonors[$skill->packageName]) ? $ownValidator : $theirValidator)
                ->validateSkill(PathDisplay::normalize((string) $skill->sourceDir));
        }
        $findings->addAsWarnings($theirs);
    }

    /**
     * Where an installed package lies, for a finding about it; its name when it is no
     * installed package, such as a fetched source.
     */
    private static function packageFile(ProjectContext $context, string $package): string
    {
        $installed = $context->composer?->getRepositoryManager()->getLocalRepository()->findPackage($package, '*');
        if ($installed === null || $context->composer === null) {
            return $package;
        }
        $path = $context->composer->getInstallationManager()->getInstallPath($installed);

        return $path === null || $path === '' ? $package : $context->paths->show(PathDisplay::normalize($path));
    }

    /**
     * Whether some donor is reachable only over the network: a `sources[]` entry other than
     * `dir`, or a source an installed package declares.
     */
    private static function hasRemoteDonors(ProjectContext $context): bool
    {
        foreach ($context->config?->sources ?? [] as $entry) {
            if (!ProviderId::isPathOnlySource($entry->from)) {
                return true;
            }
        }
        if ($context->composer === null || $context->config?->vendorSources === false) {
            return false;
        }
        foreach ($context->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            if (VendorConfigMapper::declaresSources($package->getExtra())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @psalm-pure
     */
    private static function describeFailure(SourceFailure $failure): string
    {
        return \sprintf(
            'source %s: %s%s, so skills:update installs nothing from it',
            $failure->label,
            $failure->summary,
            $failure->detail !== null ? ' (' . $failure->detail . ')' : '',
        );
    }
}
