<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

/**
 * Configuration declared by the **consumer project** under
 * `extra.skills` in its `composer.json`.
 *
 * A broken root config is fatal: the project owns this file, so we surface
 * the error loudly instead of silently degrading.
 *
 * @psalm-immutable
 */
final readonly class ProjectConfig
{
    /**
     * Default destination directory, relative to the project root.
     *
     * `.agents/` is tool-agnostic — Claude Code, Cursor, and other coding
     * agents can point at the same place. Projects targeting a single
     * agent can redirect via `extra.skills.target` (e.g. `.claude/skills`).
     */
    public const DEFAULT_TARGET = '.agents/skills';

    /**
     * @param non-empty-string $target       destination relative to project root
     * @param TrustedVendors   $trusted      patterns from project `extra.skills.trusted`
     * @param bool             $trustedReplace when true, skip the built-in trusted list entirely
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $target,
        public TrustedVendors $trusted,
        public bool $trustedReplace,
    ) {}

    /**
     * The state we use when the consumer's `composer.json` does not declare
     * any `extra.skills` block at all.
     *
     * @psalm-pure
     */
    public static function default(): self
    {
        return new self(
            target: self::DEFAULT_TARGET,
            trusted: TrustedVendors::empty(),
            trustedReplace: false,
        );
    }

    /**
     * @param non-empty-string $target
     *
     * @psalm-mutation-free
     */
    public function withTarget(string $target): self
    {
        return new self($target, $this->trusted, $this->trustedReplace);
    }
}
