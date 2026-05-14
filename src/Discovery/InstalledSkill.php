<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use Internal\Path;

/**
 * A skill directory that currently exists in the project's target
 * directory.
 *
 * Distinct from {@see Skill} (which lives inside a donor package and is a
 * *candidate* for sync). An {@see InstalledSkill} is the materialised
 * state on disk — what the `show` command marks as `[✓]`.
 *
 * The pairing rule between the two is by directory name: an
 * {@see InstalledSkill} with `name === 'refactor'` matches a {@see Skill}
 * with `name === 'refactor'`.
 *
 * @psalm-immutable
 */
final readonly class InstalledSkill
{
    /**
     * @param non-empty-string $name skill directory name
     * @param Path $dir absolute path to the installed skill directory
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $name,
        public Path $dir,
    ) {}
}
