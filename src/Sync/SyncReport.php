<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

/**
 * Structured outcome of one {@see SyncEngine::sync()} call.
 *
 * Three orthogonal channels:
 *
 * - `copied`    — skills successfully written into the target directory.
 * - `conflicts` — skill-name collisions detected up front; when non-empty,
 * the engine aborted *before* any file write, so `copied` is
 * always `[]` in that case.
 * - `warnings`  — soft, non-fatal diagnostics (missing source dir, empty
 * source, …). Sync continues and other donors are still
 * processed.
 *
 * @psalm-immutable
 */
final readonly class SyncReport
{
    /**
     * @param list<Skill>         $copied
     * @param list<SkillConflict> $conflicts
     * @param list<string>        $warnings
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public array $copied,
        public array $conflicts,
        public array $warnings,
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
