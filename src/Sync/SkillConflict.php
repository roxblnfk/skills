<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

/**
 * Two or more donor packages declared a skill with the same directory name.
 *
 * When the engine detects any conflict, sync aborts before touching the
 * filesystem: we refuse to silently pick a winner.
 *
 * @psalm-immutable
 */
final readonly class SkillConflict
{
    /**
     * @param non-empty-string         $name     conflicting skill directory name
     * @param list<non-empty-string>   $packages Composer names of the conflicting donors,
     *                                           in the order they were enumerated
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $name,
        public array $packages,
    ) {}
}
