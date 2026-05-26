<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

/**
 * Outcome of {@see ProjectConfigMigrator::migrate()}.
 *
 * Distinguishes three states the caller cares about:
 *
 * - **Migrated** — `skills.json` was written and (when applicable)
 *   `composer.json` had its inline project keys stripped. The caller
 *   should reload project config from the fresh file.
 * - **Skipped** — nothing to migrate (already done, or no inline
 *   block to begin with). Not an error.
 * - **Failed** — migration was eligible but a step (read, parse,
 *   write) errored out. The error message has already been emitted
 *   on `IOInterface`; the caller turns this into a non-zero exit
 *   code.
 *
 * @psalm-immutable
 */
final readonly class MigrationResult
{
    /**
     * @param list<non-empty-string> $migratedKeys names of project keys that moved
     *        out of `extra.skills` into `skills.json`. Empty when status is
     *        {@see MigrationStatus::Skipped} or {@see MigrationStatus::Failed}.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public MigrationStatus $status,
        public array $migratedKeys = [],
    ) {}

    /**
     * @psalm-pure
     */
    public static function skipped(): self
    {
        return new self(MigrationStatus::Skipped);
    }

    /**
     * @param list<non-empty-string> $keys
     *
     * @psalm-pure
     */
    public static function migrated(array $keys): self
    {
        return new self(MigrationStatus::Migrated, $keys);
    }

    /**
     * @psalm-pure
     */
    public static function failed(): self
    {
        return new self(MigrationStatus::Failed);
    }
}
