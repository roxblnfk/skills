<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use Internal\Path;

/**
 * A single skill discovered inside a donor package's source directory.
 *
 * Skills are identified by their directory name. The same skill name appearing
 * in two different donor packages produces a {@see SkillConflict}.
 *
 * @psalm-immutable
 */
final readonly class Skill
{
    /**
     * @param non-empty-string $name        directory name; this is the skill identity
     * @param Path             $sourceDir   absolute path to the skill directory inside the donor
     * @param non-empty-string $packageName Composer name of the donor (for diagnostics)
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $name,
        public Path $sourceDir,
        public string $packageName,
    ) {}
}
