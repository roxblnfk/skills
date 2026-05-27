<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use Internal\Path;

/**
 * A single skill found inside a donor package's source directory.
 *
 * Two identifiers travel alongside each other:
 *
 * - **`$name`** — the directory name. This is what the file system sees:
 *   what the donor ships, what gets copied into the target, and what
 *   the conflict detector uses (two skills writing to the same target
 *   directory clash regardless of their canonical names).
 * - **`$canonicalName`** — the skill's logical identity, read from its
 *   `SKILL.md` frontmatter `name:` field. This is what `--skill <name>`
 *   matches against, and what tools and prompts use to reference the
 *   skill. Falls back to the directory name when the frontmatter is
 *   missing or doesn't carry a `name:` entry, keeping pre-existing
 *   skills that didn't bother with a manifest unaffected.
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
     * @param non-empty-string $name directory name as it lives in the donor's source
     *         tree; drives the target directory the sync writes into
     * @param non-empty-string $canonicalName logical skill identity from the
     *         `SKILL.md` `name:` frontmatter field; falls back to `$name` when no
     *         frontmatter exists. This is what `--skill` allowlists are matched on.
     * @param Path $sourceDir absolute path to the skill directory inside the donor
     * @param non-empty-string $packageName Composer name of the donor (for diagnostics)
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $name,
        public string $canonicalName,
        public Path $sourceDir,
        public string $packageName,
    ) {}
}
