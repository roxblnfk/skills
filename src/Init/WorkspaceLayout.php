<?php

declare(strict_types=1);

namespace LLM\Skills\Init;

/**
 * Result of probing the project root for agent directories that are
 * already in use — the shape `skills:init` proposes when the project
 * has no configuration of its own yet.
 *
 * @psalm-immutable
 */
final readonly class WorkspaceLayout
{
    /**
     * @param non-empty-string $target directory skills are physically written to
     * @param list<non-empty-string> $aliases paths that should mirror `$target` via a
     *        symlink / junction. Only paths that can actually become a link are listed:
     *        a directory holding real files cannot, and lands in `$collisions` instead
     * @param list<non-empty-string> $collisions agent directories found on disk that
     *        hold their own content (or a link pointing somewhere other than `$target`).
     *        Proposing them as aliases would fail at sync time, so they are reported
     *        to the user and left alone
     * @param bool $detected whether the probe found any agent directory at all;
     *        `false` means every value above is the built-in default
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $target,
        public array $aliases = [],
        public array $collisions = [],
        public bool $detected = false,
    ) {}
}
