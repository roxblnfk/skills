<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

/**
 * How a sync that deletes installed skills before copying behaves where it
 * cannot do the full wipe it set out to do.
 *
 * Two origins, two temperaments:
 *
 * - **Requested** — `--clean` on this run. The user is there to answer, so
 *   the run asks before deleting, and refuses or fails instead of settling
 *   for less than the wipe that was asked for.
 * - **Configured** — the `skills.json` `clean` key, picked up by a run that
 *   passed neither `--clean` nor `--no-clean`. It runs unattended, the way
 *   auto-sync after `composer install` does, so it never asks and falls back
 *   to the most it can safely do.
 *
 * @psalm-immutable
 */
final readonly class CleanPolicy
{
    private function __construct(
        private bool $requested,
    ) {}

    /**
     * @param bool|null $flag `--clean` / `--no-clean`, `null` when neither was passed
     * @param bool $configured whether `skills.json` turns clean on for this run's target
     *
     * @return self|null `null` when the run merges
     *
     * @psalm-pure
     */
    public static function resolve(?bool $flag, bool $configured): ?self
    {
        return match ($flag) {
            true => new self(requested: true),
            false => null,
            null => $configured ? new self(requested: false) : null,
        };
    }

    /**
     * Whether an unscoped run stops when a donor contributed nothing. Otherwise
     * it deletes only the skills it reinstalls.
     *
     * @psalm-mutation-free
     */
    public function refusesPartialRestore(): bool
    {
        return $this->requested;
    }

    /**
     * Whether a target that cannot be listed stops the run. Otherwise the run
     * merges into it.
     *
     * @psalm-mutation-free
     */
    public function refusesUnreadableTarget(): bool
    {
        return $this->requested;
    }

    /**
     * Whether an interactive session is asked before anything is deleted.
     *
     * @psalm-mutation-free
     */
    public function asksFirst(): bool
    {
        return $this->requested;
    }

    /**
     * Whether a skill that could not be deleted fails the run, since what
     * landed there is a merge rather than a clean install.
     *
     * @psalm-mutation-free
     */
    public function failsOnRemovalError(): bool
    {
        return $this->requested;
    }
}
