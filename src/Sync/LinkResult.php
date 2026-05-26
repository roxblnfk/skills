<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use Internal\Path;

/**
 * Outcome of a single {@see SymlinkLinker::link()} call.
 *
 * The linker never throws on filesystem-level failures: each alias is
 * independent, and the runner needs to print a per-alias outcome and
 * decide on the exit code only once the whole list has been processed.
 * Errors travel inside this object so the caller can decide whether to
 * surface them as warnings, fail the run, or both.
 *
 * Statuses:
 *
 * - {@see LinkStatus::Created}        — a new junction/symlink was put in place.
 * - {@see LinkStatus::AlreadyCorrect} — the alias path already pointed at the target; no-op.
 * - {@see LinkStatus::WouldCreate}    — dry-run; nothing was written, but the linker would have created it.
 * - {@see LinkStatus::Failed}         — a state-matrix rejection (alias path occupied, points elsewhere, cross-volume on Windows, etc.). `$reason` is non-null.
 *
 * @psalm-immutable
 */
final readonly class LinkResult
{
    /**
     * @param Path $alias the alias path the linker tried to create
     * @param Path $target absolute path the alias points at (or would have pointed at)
     * @param non-empty-string|null $reason human-readable failure detail; non-null only when `$status` is
     *         {@see LinkStatus::Failed}
     *
     * @psalm-mutation-free
     */
    private function __construct(
        public Path $alias,
        public Path $target,
        public LinkStatus $status,
        public ?string $reason = null,
    ) {}

    /**
     * @psalm-pure
     */
    public static function created(Path $alias, Path $target): self
    {
        return new self($alias, $target, LinkStatus::Created);
    }

    /**
     * @psalm-pure
     */
    public static function alreadyCorrect(Path $alias, Path $target): self
    {
        return new self($alias, $target, LinkStatus::AlreadyCorrect);
    }

    /**
     * @psalm-pure
     */
    public static function wouldCreate(Path $alias, Path $target): self
    {
        return new self($alias, $target, LinkStatus::WouldCreate);
    }

    /**
     * @param non-empty-string $reason
     *
     * @psalm-pure
     */
    public static function failed(Path $alias, Path $target, string $reason): self
    {
        return new self($alias, $target, LinkStatus::Failed, $reason);
    }

    /**
     * @psalm-mutation-free
     */
    public function isFailure(): bool
    {
        return $this->status === LinkStatus::Failed;
    }
}
