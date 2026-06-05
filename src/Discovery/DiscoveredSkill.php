<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use Internal\Path;

/**
 * A single skill directory found by {@see SkillTreeScanner} inside a package
 * that does **not** declare `extra.skills`.
 *
 * Unlike a declared donor — where one `source` directory holds every skill as
 * an immediate subdirectory — auto-discovered skills can sit at varying depths
 * (`skills/<name>/`, `skills/<category>/<name>/`, `.claude/skills/<name>/`, …).
 * Each found skill therefore travels with its own absolute {@see $dir} plus the
 * relative {@see $container} it was found under, so {@see DonorDiscovery} can
 * group skills sharing a container into one discovered donor row.
 *
 * @psalm-immutable
 */
final readonly class DiscoveredSkill
{
    /**
     * @param Path $dir absolute path to the skill directory (the one holding `SKILL.md`)
     * @param non-empty-string $name the skill directory's own name — drives the target
     *        directory the sync writes into
     * @param non-empty-string $container relative path (from the package root) of the
     *        directory that *contains* {@see $dir}; used to group skills into donors and
     *        to label the donor's `source` in `show`/`update` output. `.` for skills that
     *        sit directly under the package root.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public Path $dir,
        public string $name,
        public string $container,
    ) {}
}
