<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use LLM\Skills\Discovery\Skill;

/**
 * Structured outcome of one {@see SyncEngine::sync()} call.
 *
 * Two channels:
 *
 * - `copied`    — skills successfully written into the target directory
 *                 (or that *would* have been written, in dry-run mode).
 * - `conflicts` — skill-name collisions detected up front; when non-empty,
 *                 the engine aborted **before** any file write, so `copied`
 *                 is always `[]` in that case.
 *
 * Discovery-time warnings (missing source dir, malformed extra, etc.) are
 * **not** part of this report — they happen earlier in the pipeline and
 * are surfaced by the caller as they appear.
 *
 * @psalm-immutable
 */
final readonly class SyncReport
{
    /**
     * @param list<Skill>         $copied
     * @param list<SkillConflict> $conflicts
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public array $copied,
        public array $conflicts,
    ) {}

    /**
     * @psalm-mutation-free
     */
    public function isSuccess(): bool
    {
        return $this->conflicts === [];
    }

    /**
     * @psalm-mutation-free
     */
    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }
}
