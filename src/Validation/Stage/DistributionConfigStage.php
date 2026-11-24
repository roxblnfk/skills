<?php

declare(strict_types=1);

namespace LLM\Skills\Validation\Stage;

use LLM\Skills\Config\Exception\MalformedVendorConfig;
use LLM\Skills\Config\Mapper\VendorConfigMapper;
use LLM\Skills\Pipeline\ProjectContext;
use LLM\Skills\Pipeline\Stage;
use LLM\Skills\Validation\FindingCollector;
use LLM\Skills\Validation\PathDisplay;

/**
 * Checks what the root package declares for the projects that install it: the
 * `extra.skills.source` directories and the `extra.skills.sources` list, read as a consumer
 * reads them. Publishes the source directories for later stages.
 *
 * @internal
 */
final class DistributionConfigStage implements Stage
{
    /**
     * Stands in for the package name a consumer would see: the reason is reported against
     * `composer.json`, without the name.
     */
    private const PACKAGE = 'root';

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly VendorConfigMapper $mapper = new VendorConfigMapper(),
    ) {}

    #[\Override]
    public function run(ProjectContext $context, FindingCollector $findings): void
    {
        if (VendorConfigMapper::declaresSkills($context->extra)) {
            try {
                $sources = [];
                foreach ($this->mapper->fromExtra(self::PACKAGE, $context->projectRoot, $context->extra) as $donor) {
                    $sources[] = PathDisplay::normalize((string) $donor->sourcePath());
                }
                $context->distributedSources = $sources;
            } catch (MalformedVendorConfig $e) {
                $findings->error('composer.json', null, 'distribution-invalid', $e->reason);
            } catch (\LogicException $e) {
                // Internal\Path refuses a path that climbs above the filesystem root.
                $findings->error('composer.json', null, 'distribution-invalid', 'extra.skills.source must not escape the package root (' . $e->getMessage() . ')');
            }
        }

        try {
            $this->mapper->sourceEntriesFromExtra(self::PACKAGE, $context->extra);
        } catch (MalformedVendorConfig $e) {
            $findings->error('composer.json', null, 'distribution-invalid', $e->reason);
        }
    }
}
