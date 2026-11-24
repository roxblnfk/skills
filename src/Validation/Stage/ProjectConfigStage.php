<?php

declare(strict_types=1);

namespace LLM\Skills\Validation\Stage;

use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\Mapper\ExternalProjectConfigLoader;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\TrustedVendors;
use LLM\Skills\Pipeline\ProjectContext;
use LLM\Skills\Pipeline\Stage;
use LLM\Skills\Sync\SyncPlanner;
use LLM\Skills\Validation\FindingCollector;

/**
 * Resolves the project configuration (`skills.json`, `skills.local.json` or the inline
 * `extra.skills`) the way `skills:update` does, and checks its target and aliases.
 * Publishes the config and the target for later stages.
 *
 * @internal
 */
final class ProjectConfigStage implements Stage
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly ProjectConfigMapper $mapper = new ProjectConfigMapper(),
        private readonly SyncPlanner $planner = new SyncPlanner(),
    ) {}

    #[\Override]
    public function run(ProjectContext $context, FindingCollector $findings): void
    {
        $hasSkillsJson = \is_file((string) $context->projectRoot->join(ExternalProjectConfigLoader::FILE_NAME));
        $baseFile = $hasSkillsJson ? ExternalProjectConfigLoader::FILE_NAME : 'composer.json';
        $context->configFile = $baseFile;

        try {
            $resolution = $this->mapper->forProject($context->projectRoot, $context->extra);
            // The trust list plays no part in where skills go; the planner checks the
            // target and the aliases, which must stay inside the project.
            $plan = $this->planner->plan([], $resolution->config, SyncOptions::default(), TrustedVendors::empty(), $context->projectRoot);
        } catch (MalformedProjectConfig $e) {
            $findings->error(self::fileOf($e->getMessage(), $baseFile), null, 'config-invalid', $e->getMessage());

            return;
        } catch (\LogicException $e) {
            // Internal\Path refuses a path that climbs above the filesystem root.
            $findings->error($baseFile, null, 'config-invalid', 'a configured path climbs above the filesystem root (' . $e->getMessage() . '); target and aliases must stay inside the project');

            return;
        }
        $context->config = $resolution->config;
        $context->target = $plan->target;

        if ($resolution->ignoredInlineKeys !== []) {
            $findings->warn('composer.json', null, 'config-inline-ignored', \sprintf(
                'skills.json is present, so these extra.skills keys are ignored: %s; move them into skills.json or delete them',
                \implode(', ', $resolution->ignoredInlineKeys),
            ));
        }
        if ($resolution->usedDeprecatedSourcesKey) {
            $findings->warn(
                $resolution->deprecatedSourcesKeyInline ? 'composer.json' : ExternalProjectConfigLoader::FILE_NAME,
                null,
                'config-deprecated-key',
                $resolution->deprecatedSourcesKeyInline
                    ? 'the inline extra.skills.remote list is not fetched; run `composer skills:update` to move it into "sources" in skills.json'
                    : 'config key "remote" was renamed to "sources"; run `composer skills:update` to migrate the file',
            );
        }
        if ($resolution->usedDeprecatedDependencyKeys !== []) {
            $findings->warn($baseFile, null, 'config-deprecated-key', \sprintf(
                'config keys %s were replaced by "dependencies"; run `composer skills:update` to migrate the file',
                \implode(', ', \array_map(static fn(string $k): string => '"' . $k . '"', $resolution->usedDeprecatedDependencyKeys)),
            ));
        }
        if (!$hasSkillsJson && self::inlineProjectKeys($context->extra) !== []) {
            $findings->info('composer.json', null, 'config-inline-legacy', \sprintf(
                'project settings live in extra.skills (%s); run `composer skills:update` to move them into skills.json',
                \implode(', ', self::inlineProjectKeys($context->extra)),
            ));
        }
    }

    /**
     * The config file an error message names, else the file holding the base configuration.
     *
     * @psalm-pure
     */
    private static function fileOf(string $message, string $baseFile): string
    {
        foreach ([ExternalProjectConfigLoader::LOCAL_FILE_NAME, ExternalProjectConfigLoader::FILE_NAME] as $file) {
            if (\str_starts_with($message, $file)) {
                return $file;
            }
        }

        return \str_starts_with($message, 'extra') ? 'composer.json' : $baseFile;
    }

    /**
     * @return list<string>
     *
     * @psalm-pure
     */
    private static function inlineProjectKeys(mixed $extra): array
    {
        /** @var mixed $skills */
        $skills = \is_array($extra) ? ($extra['skills'] ?? null) : null;
        if (!\is_array($skills)) {
            return [];
        }

        return \array_values(\array_filter(
            ProjectConfigMapper::PROJECT_KEYS,
            static fn(string $key): bool => \array_key_exists($key, $skills),
        ));
    }
}
