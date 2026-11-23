<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs `lint`: validates the given paths and prints the findings through
 * {@see FindingReport}.
 *
 * @internal
 */
final class LintRunner
{
    /**
     * @param list<string> $paths skill directories, `SKILL.md` files or directories to search;
     *        empty validates the working directory
     *
     * @return FindingReport::EXIT_* exit code
     */
    public function run(string $cwd, array $paths, bool $json, OutputInterface $output): int
    {
        $findings = new FindingCollector();
        $validator = new SkillValidator($findings, new PathDisplay($cwd));
        $explicit = $paths !== [];
        foreach ($explicit ? $paths : ['.'] as $path) {
            $validator->validatePath($path, $explicit);
        }
        $validator->reportCollisions();

        return FindingReport::write($findings, $json, $output);
    }
}
