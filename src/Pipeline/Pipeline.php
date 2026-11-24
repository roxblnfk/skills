<?php

declare(strict_types=1);

namespace LLM\Skills\Pipeline;

use LLM\Skills\Validation\FindingCollector;

/**
 * Runs stages in order over one project, collecting their findings in report order.
 *
 * @internal
 */
final class Pipeline
{
    /**
     * @param list<Stage> $stages
     *
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly array $stages,
    ) {}

    public function run(ProjectContext $context): FindingCollector
    {
        $findings = new FindingCollector();
        foreach ($this->stages as $stage) {
            $stage->run($context, $findings);
        }

        return $findings;
    }
}
