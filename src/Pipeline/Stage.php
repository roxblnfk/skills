<?php

declare(strict_types=1);

namespace LLM\Skills\Pipeline;

use LLM\Skills\Validation\FindingCollector;

/**
 * One step of a {@see Pipeline}: inspects part of the project and records what it finds.
 *
 * A stage reads what earlier stages published on the {@see ProjectContext} and publishes
 * its own results there; it skips quietly when an input it needs is missing, since the
 * stage that failed to produce it has already reported why.
 *
 * @internal
 *
 * @psalm-mutable stages read the filesystem and write to the context
 */
interface Stage
{
    /**
     * @psalm-impure
     */
    public function run(ProjectContext $context, FindingCollector $findings): void;
}
