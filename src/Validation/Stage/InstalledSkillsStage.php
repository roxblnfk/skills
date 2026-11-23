<?php

declare(strict_types=1);

namespace LLM\Skills\Validation\Stage;

use LLM\Skills\Pipeline\ProjectContext;
use LLM\Skills\Pipeline\Stage;
use LLM\Skills\Sync\SkillConflict;
use LLM\Skills\Validation\FindingCollector;
use LLM\Skills\Validation\PathDisplay;
use LLM\Skills\Validation\SkillFiles;
use LLM\Skills\Validation\SkillValidator;

/**
 * Validates the skills installed in the project's target directory, which its agents load:
 * every subdirectory holding a `SKILL.md` that is no copy of a donor's skill, such as one
 * put there by hand. Entry names are kept as listed, so a name the
 * plugin's path handling would normalize is reported as it is on disk.
 *
 * @internal
 */
final readonly class InstalledSkillsStage implements Stage
{
    #[\Override]
    public function run(ProjectContext $context, FindingCollector $findings): void
    {
        if ($context->target === null) {
            return;
        }

        $target = PathDisplay::normalize((string) $context->target);
        if (!\is_dir($target)) {
            return;
        }
        if (@\scandir($target) === false) {
            $findings->error($context->paths->show($target), null, 'target-unreadable', 'skills target directory cannot be read; fix its permissions');

            return;
        }

        // Without every donor read, a skill matching none may be a copy of an unread donor's,
        // which the project cannot fix.
        $own = $context->allDonorsRead ? $findings : new FindingCollector();
        $validator = new SkillValidator($own, $context->paths, $context->validatedSkills);
        foreach (SkillFiles::entries($target) as $entry) {
            $dir = "$target/$entry";
            if (!\is_dir($dir) || SkillFiles::skillFileName($dir) === null) {
                continue;
            }
            // A copy of a donor's skill, checked where the donor ships it.
            if (isset($context->donorSkillKeys[SkillConflict::collisionKey($entry)])) {
                continue;
            }
            if ($entry[0] === '.') {
                $findings->info($context->paths->show($dir), null, 'hidden-dir-skipped', 'hidden skill directory is never installed by llm/skills, so it is not validated; it was put there by hand, rename it if agents should load it');

                continue;
            }
            $validator->validateSkill($dir);
        }
        $validator->reportCollisions();

        if ($own !== $findings) {
            $findings->addAsWarnings($own);
        }
    }
}
