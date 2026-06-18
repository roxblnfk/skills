<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

use LLM\Skills\Discovery\Provider\ProviderId;

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
     * @param non-empty-string $target destination relative to project root
     * @param TrustedVendors $trusted patterns from project `extra.skills.trusted`
     * @param bool $trustedReplace when true, skip the built-in trusted list entirely
     * @param bool $discovery when true, treat installed packages without `extra.skills` as
     *         potential donors if they ship a `skills/` directory; CLI `--discovery` overrides this
     * @param list<non-empty-string> $aliases extra paths that should be created as junctions
     *         (Windows) or symbolic links (POSIX) pointing at the resolved `$target`. The target
     *         itself is the only place skills are physically written; aliases are mirrors. Empty
     *         list means "no aliases", which is the default and matches the entire 1.x contract.
     * @param bool $autoSync when true, the plugin runs `skills:update` automatically after
     *         every `composer install` / `composer update`, removing the need to wire up
     *         `scripts.post-install-cmd` and `scripts.post-update-cmd` by hand
     * @param bool $allowExternalTarget when true, `target` may resolve outside the project root
     * @param array<non-empty-string, bool> $local local-provider toggles. Keys are provider ids
     *         from {@see ProviderId::LOCAL_IDS}; values turn the provider on/off. Absent keys
     *         fall back to {@see ProviderId::defaultLocalEnabled()} — `composer` defaults to
     *         enabled (preserves the pre-`local` behaviour), every other id defaults to off
     *         so a new provider stays opt-in until its implementation lands.
     * @param list<RemoteEntry> $remote remote donor refs declared by the project. The remote
     *         provider treats each entry as an explicit fetch target. Empty list means
     *         the remote provider stays inactive — symmetric with `local.composer == false`.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $target,
        public TrustedVendors $trusted,
        public bool $trustedReplace,
        public bool $discovery = false,
        public array $aliases = [],
        public bool $autoSync = true,
        public bool $allowExternalTarget = false,
        public array $local = [],
        public array $remote = [],
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
            discovery: false,
            aliases: [],
            autoSync: true,
            allowExternalTarget: false,
            local: [],
            remote: [],
        );
    }

    /**
     * Whether the given local-provider id is enabled for this project.
     * Explicit `local: { <id>: bool }` settings win; absent keys fall
     * back to the per-provider default
     * ({@see ProviderId::defaultLocalEnabled()}).
     *
     * @psalm-mutation-free
     */
    public function isLocalEnabled(string $providerId): bool
    {
        if (\array_key_exists($providerId, $this->local)) {
            return $this->local[$providerId];
        }

        return ProviderId::defaultLocalEnabled($providerId);
    }

    /**
     * @param non-empty-string $target
     *
     * @psalm-mutation-free
     */
    public function withTarget(string $target): self
    {
        return new self(
            $target,
            $this->trusted,
            $this->trustedReplace,
            $this->discovery,
            $this->aliases,
            $this->autoSync,
            $this->allowExternalTarget,
            $this->local,
            $this->remote,
        );
    }

    /**
     * @param list<non-empty-string> $aliases
     *
     * @psalm-mutation-free
     */
    public function withAliases(array $aliases): self
    {
        return new self(
            $this->target,
            $this->trusted,
            $this->trustedReplace,
            $this->discovery,
            $aliases,
            $this->autoSync,
            $this->allowExternalTarget,
            $this->local,
            $this->remote,
        );
    }
}
