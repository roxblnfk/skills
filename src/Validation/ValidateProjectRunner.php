<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

use Composer\Composer;
use Internal\Path;
use LLM\Skills\Pipeline\Pipeline;
use LLM\Skills\Pipeline\ProjectContext;
use LLM\Skills\Validation\Stage\ComposerJsonStage;
use LLM\Skills\Validation\Stage\DistributedSkillsStage;
use LLM\Skills\Validation\Stage\DistributionConfigStage;
use LLM\Skills\Validation\Stage\DonorSkillsStage;
use LLM\Skills\Validation\Stage\InstalledSkillsStage;
use LLM\Skills\Validation\Stage\ProjectConfigStage;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs `validate`: checks the project configuration, what the root package declares for
 * distribution and the skills it ships, the skills the next sync would install from donors,
 * and the other skills in its target, then prints the findings through {@see FindingReport}.
 *
 * Each skill directory is validated once, by the first stage reaching it: the shipped skills
 * come first, so a skill that is also a donor's or installed is reported under the path its
 * author edits.
 *
 * @internal
 */
final class ValidateProjectRunner
{
    /**
     * @param Path $projectRoot absolute project root
     * @param Composer|null $composer the project's Composer instance; without one installed
     *        packages are not read
     * @param bool $fetch whether donors that need the network are fetched
     *
     * @return FindingReport::EXIT_* exit code
     */
    public function run(Path $projectRoot, ?Composer $composer, bool $fetch, bool $json, OutputInterface $output): int
    {
        $pipeline = new Pipeline([
            new ComposerJsonStage(),
            new ProjectConfigStage(),
            new DistributionConfigStage(),
            new DistributedSkillsStage(),
            new DonorSkillsStage(),
            new InstalledSkillsStage(),
        ]);

        return FindingReport::write(
            $pipeline->run(new ProjectContext($projectRoot, $composer, $fetch)),
            $json,
            $output,
        );
    }
}
