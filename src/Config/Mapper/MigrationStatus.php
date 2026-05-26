<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

enum MigrationStatus
{
    /**
     * Files were written; project config now lives in skills.json.
     */
    case Migrated;

    /**
     * No-op — skills.json already there, or no inline block to migrate.
     */
    case Skipped;

    /**
     * Migration was eligible but failed mid-flight; IO already informed.
     */
    case Failed;
}
