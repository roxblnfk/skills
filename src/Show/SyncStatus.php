<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

/**
 * Per-skill status reported by `skills:show`.
 *
 * Three states answer one question: "If I run `skills:update` right now,
 * what changes for this skill?"
 *
 * - {@see SyncStatus::NotSynced} — there is no copy in the target.
 *   `update` will create it.
 * - {@see SyncStatus::InSync} — the target copy exists and matches the
 *   donor byte-for-byte. `update` would be a no-op for this skill.
 * - {@see SyncStatus::Drift} — the target copy exists but differs from
 *   the donor (donor-owned files differ or are missing locally).
 *   `update` would overwrite the differing files.
 *
 * User-added files inside a synced skill directory are **not** drift —
 * `skills:update` is non-destructive and never touches them. Drift is
 * strictly about donor-owned content.
 */
enum SyncStatus: string
{
    case NotSynced = 'not-synced';
    case InSync = 'in-sync';
    case Drift = 'drift';
}
