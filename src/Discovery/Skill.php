<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use Internal\Path;

/**
 * A single skill found inside a donor package's source directory.
 *
 * Skills are identified by their directory name. Two skills with the same
 * name coming from different donor packages are a conflict — see
 * {@see \LLM\Skills\Sync\SkillConflict}.
 *
 * Produced by {@see SkillEnumerator}; consumed by
 * {@see \LLM\Skills\Sync\SyncEngine} (writes them) and by the `show`
 * command (lists them).
 *
 * @psalm-immutable
 */
final readonly class Skill
{
    /**
     * @param non-empty-string $name directory name; this is the skill identity
     * @param Path $sourceDir absolute path to the skill directory inside the donor
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
