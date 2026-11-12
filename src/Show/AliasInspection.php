<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

use Internal\Path;

/**
 * One row of `skills:show`'s `Aliases:` header.
 *
 * Carries the configured alias path plus, when present, the path the
 * alias currently resolves to on disk if it does **not** point at the
 * configured target. Used to flag "this alias exists but points at the
 * wrong place" without having to read the formatter's mind from raw
 * paths alone.
 *
 * @psalm-immutable
 */
final readonly class AliasInspection
{
    /**
     * @param Path $alias absolute alias path as configured
     * @param non-empty-string|null $driftResolvedTo absolute path the alias currently resolves to,
     *         non-null **only** when the alias exists on disk and resolves somewhere other than the
     *         configured target. `null` means "no drift" (either the link is correct or the alias
     *         doesn't exist yet).
     * @param non-empty-string|null $relinkFrom what the link at the alias points at when the next sync
     *         re-points it: a link leading elsewhere or nowhere, or an absolute POSIX symlink to the
     *         target. `null` when the sync leaves the alias as is.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public Path $alias,
        public ?string $driftResolvedTo = null,
        public ?string $relinkFrom = null,
    ) {}

    /**
     * @psalm-mutation-free
     */
    public function hasDrift(): bool
    {
        return $this->driftResolvedTo !== null;
    }
}
