<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use Internal\Path;
use LLM\Skills\Config\VendorConfig;

/**
 * Looks inside each donor's source directory and lists the immediate
 * subdirectories as {@see Skill} candidates.
 *
 * One subdir → one skill, named after the directory. Loose files at the
 * source root (e.g. `README.md`) are ignored — they are not skills.
 *
 * Failures are soft: a missing or unreadable `source` directory becomes
 * a warning and that donor is dropped from the result. The caller (or
 * `-v` verbosity) decides whether to print the warning.
 */
final readonly class SkillEnumerator
{
    /**
     * @param list<VendorConfig> $donors
     */
    public function enumerate(array $donors): SkillEnumerationResult
    {
        $skills = [];
        $warnings = [];

        foreach ($donors as $donor) {
            $sourcePath = (string) $donor->sourcePath();

            if (!\is_dir($sourcePath)) {
                $warnings[] = \sprintf(
                    '%s: source directory "%s" does not exist',
                    $donor->packageName,
                    $donor->source,
                );
                continue;
            }

            $entries = \scandir($sourcePath);
            if ($entries === false) {
                $warnings[] = \sprintf(
                    '%s: source directory "%s" is unreadable',
                    $donor->packageName,
                    $donor->source,
                );
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $skillPath = $sourcePath . \DIRECTORY_SEPARATOR . $entry;
                if (!\is_dir($skillPath)) {
                    // Files at the source root are not skills. Ignore silently.
                    continue;
                }

                /** @var non-empty-string $entry */
                $skills[] = new Skill(
                    name: $entry,
                    sourceDir: Path::create($skillPath),
                    packageName: $donor->packageName,
                );
            }
        }

        return new SkillEnumerationResult(skills: $skills, warnings: $warnings);
    }
}
