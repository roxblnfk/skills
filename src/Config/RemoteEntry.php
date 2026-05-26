<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

/**
 * One entry of the `remote[]` list in `skills.json`.
 *
 * Each entry tells the remote provider what to fetch and where from:
 * `from` is the adapter id (a value from {@see \LLM\Skills\Discovery\Provider\ProviderId}),
 * `package` or `url` is the identifier inside that adapter's namespace,
 * `host` overrides the adapter's default registry (private Packagist /
 * GitHub Enterprise / self-hosted GitLab / …), `ref` is an
 * adapter-specific version pin, and `extras` carries adapter-specific
 * optional keys (e.g. `sha256` on `zip`).
 *
 * Exactly one of `package` and `url` is set — the mapper enforces this
 * up-front; downstream code can rely on {@see self::identifier()}
 * never being empty.
 *
 * @psalm-immutable
 */
final readonly class RemoteEntry
{
    /**
     * @param non-empty-string $from adapter id, e.g. {@see \LLM\Skills\Discovery\Provider\ProviderId::GITHUB}
     * @param non-empty-string|null $package adapter-namespaced identifier (`acme/skills`,
     *         `@scope/pkg`, `github.com/owner/mod`, …). Mutually exclusive with `$url`.
     * @param non-empty-string|null $url full URL — used by URL-only adapters
     *         ({@see \LLM\Skills\Discovery\Provider\ProviderId::HTTP},
     *         {@see \LLM\Skills\Discovery\Provider\ProviderId::ZIP}). Mutually exclusive with `$package`.
     * @param non-empty-string|null $host explicit registry / API host override; absent means
     *         "use the adapter's default" (e.g. `https://api.github.com` for github).
     * @param non-empty-string|null $ref adapter-specific version pin (`^1.2.3`, `v1.2.3`,
     *         `main`, full SHA, …). Absent means the adapter picks the latest stable
     *         tag, falling back to the default branch HEAD.
     * @param array<string, mixed> $extras adapter-specific extra keys preserved verbatim
     *         (e.g. `{"sha256": "…"}` on `zip`). Empty map when the entry has no extras.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $from,
        public ?string $package,
        public ?string $url,
        public ?string $host,
        public ?string $ref,
        public array $extras = [],
    ) {}

    /**
     * Identifier inside the adapter's namespace — `$package` when set,
     * `$url` otherwise. The mapper guarantees exactly one is non-null,
     * so the return value is always a non-empty string.
     *
     * @return non-empty-string
     *
     * @psalm-mutation-free
     */
    public function identifier(): string
    {
        /** @var non-empty-string */
        return $this->package ?? $this->url ?? '';
    }

    /**
     * Composite uniqueness key: `(from, host, package|url)`.
     * Used by the mapper to reject duplicate entries and by `skills:add`
     * to detect upsert vs insert.
     *
     * `host` is rendered as the empty string when absent — the adapter's
     * default-host fill-in happens at resolve time, not at config-load
     * time, so two entries are duplicates only if BOTH omit `host` or
     * BOTH spell it the same way.
     *
     * @return non-empty-string
     *
     * @psalm-mutation-free
     */
    public function compositeKey(): string
    {
        /** @var non-empty-string */
        return $this->from . '|' . ($this->host ?? '') . '|' . $this->identifier();
    }
}
