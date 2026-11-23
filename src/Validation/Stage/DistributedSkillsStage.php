<?php

declare(strict_types=1);

namespace LLM\Skills\Validation\Stage;

use LLM\Skills\Pipeline\ProjectContext;
use LLM\Skills\Pipeline\Stage;
use LLM\Skills\Validation\FindingCollector;
use LLM\Skills\Validation\SkillValidator;

/**
 * Validates the skills the root package ships: every subdirectory of its declared
 * `extra.skills.source` directories, which consumers install side by side.
 *
 * @internal
 */
final readonly class DistributedSkillsStage implements Stage
{
    #[\Override]
    public function run(ProjectContext $context, FindingCollector $findings): void
    {
        if ($context->distributedSources === []) {
            return;
        }

        $validator = new SkillValidator($findings, $context->paths, $context->validatedSkills);
        foreach ($context->distributedSources as $source) {
            $validator->validateSource($source);
        }
        $validator->reportCollisions();
        $validator->reportDuplicates();
    }
}
