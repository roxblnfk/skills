<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

use LLM\Skills\Discovery\Provider\ProviderId;

/**
 * One entry of the `sources[]` list in `skills.json`.
 *
 * Each entry tells the remote provider what to fetch and where from:
 * `from` is the adapter id (a value from {@see ProviderId}),
 * the identifier inside that adapter's namespace is `package`, `url`,
 * or `path` depending on the adapter kind, `host` overrides the
 * adapter's default registry (private Packagist / GitHub Enterprise /
 * self-hosted GitLab / …), `ref` is an adapter-specific version pin,
 * and `extras` carries adapter-specific optional keys (e.g. `sha256`
 * on `zip`).
 *
 * The identifier field depends on the adapter kind:
 *
 * - path-only adapters ({@see ProviderId::DIR}) identify the donor by
 *   `path`; `url` is forbidden and `package` is only an optional name
 *   override;
 * - URL-only adapters ({@see ProviderId::HTTP}, {@see ProviderId::ZIP})
 *   identify it by `url`;
 * - name-based adapters identify it by `package`.
 *
 * The mapper enforces the adapter-specific rules up-front; downstream
 * code can rely on {@see self::identifier()} never being empty.
 *
 * @psalm-immutable
 */
final readonly class SourceEntry
{
    /**
     * @param non-empty-string $from adapter id, e.g. {@see ProviderId::GITHUB}
     * @param non-empty-string|null $package adapter-namespaced identifier (`acme/skills`,
     *         `@scope/pkg`, `github.com/owner/mod`, …). The identifier for name-based
     *         adapters; an optional donor-name override for path-only adapters.
     * @param non-empty-string|null $url full URL — used by URL-only adapters
     *         ({@see ProviderId::HTTP}, {@see ProviderId::ZIP}). Mutually exclusive
     *         with `$package`; forbidden for path-only adapters.
     * @param non-empty-string|null $host explicit registry / API host override; absent means
     *         "use the adapter's default" (e.g. `https://api.github.com` for github).
     * @param non-empty-string|null $ref adapter-specific version pin (`^1.2.3`, `v1.2.3`,
     *         `main`, full SHA, …). Absent means the adapter picks the latest stable
     *         tag, falling back to the default branch HEAD.
     * @param array<string, mixed> $extras adapter-specific extra keys preserved verbatim
     *         (e.g. `{"sha256": "…"}` on `zip`). Empty map when the entry has no extras.
     * @param list<non-empty-string>|null $skills explicit skill-name allowlist scoped to this
     *         donor. `null` (the default) means "sync every skill the donor ships". A
     *         non-empty list keeps only the listed names. The **empty list** is a third
     *         meaningful state: "this donor is registered but no skills are taken from it"
     *         — useful for staging a donor before opting into its content or for temporarily
     *         disabling a donor without deleting the entry. Names that do not exist in the
     *         fetched archive surface as `-v` warnings without aborting the sync.
     * @param non-empty-string|null $path filesystem path to the donor directory — the
     *         identifier for path-only adapters ({@see ProviderId::DIR}). Non-null exactly
     *         when `$from` is a path-only adapter; null for every other adapter kind.
     *
     * @throws \InvalidArgumentException on an identifier shape that violates the adapter kind
     *         (path-only requires `$path` and forbids `$url`; every other adapter requires
     *         exactly one of `$package` / `$url` and forbids `$path`), or if `$skills`
     *         contains a non-string / contains an empty string
     *
     * @psalm-mutation-free
     * @psalm-pure
     */
    public function __construct(
        public string $from,
        public ?string $package,
        public ?string $url,
        public ?string $host,
        public ?string $ref,
        public array $extras = [],
        public ?array $skills = null,
        public ?string $path = null,
    ) {
        // The identifier-shape invariant is the contract every
        // downstream method relies on (identifier(), compositeKey(),
        // the cache-path builder). Enforce it in the constructor so a
        // direct caller cannot smuggle a half-built VO past the mapper.
        if (ProviderId::isPathOnlySource($from)) {
            // Path-only adapters (dir) identify the donor by $path;
            // $url is meaningless and $package is only an optional
            // name override, so the exactly-one-of-package/url rule
            // does not apply.
            if ($path === null) {
                throw new \InvalidArgumentException(
                    'SourceEntry requires $path for path-only adapter "' . $from . '".',
                );
            }
            if ($url !== null) {
                throw new \InvalidArgumentException(
                    'SourceEntry does not allow $url for path-only adapter "' . $from . '".',
                );
            }
        } else {
            if ($path !== null) {
                throw new \InvalidArgumentException(
                    'SourceEntry does not allow $path for adapter "' . $from . '".',
                );
            }
            $hasPackage = $package !== null;
            $hasUrl = $url !== null;
            if ($hasPackage === $hasUrl) {
                throw new \InvalidArgumentException(
                    'SourceEntry requires exactly one of $package or $url to be non-null; got '
                    . ($hasPackage ? 'both set' : 'neither set'),
                );
            }
        }

        // The psalm-level annotation already says `list<non-empty-string>|null`,
        // but the constructor sits on a public API and is called from the
        // mapper with values that originated as user JSON; keep the
        // runtime check as defence in depth. An empty list is allowed
        // — it carries the explicit "no skills from this donor" meaning.
        if ($skills !== null) {
            /** @psalm-suppress DocblockTypeContradiction defensive runtime check */
            foreach ($skills as $name) {
                if (!\is_string($name) || $name === '') {
                    throw new \InvalidArgumentException(
                        'SourceEntry $skills must be a list of non-empty strings.',
                    );
                }
            }
        }
    }

    /**
     * Identifier inside the adapter's namespace — `$path` for path-only
     * adapters, else `$package` when set, else `$url`. The constructor
     * enforces that exactly one of these is populated for the adapter
     * kind, so the return value is always a non-empty string.
     *
     * @return non-empty-string
     *
     * @psalm-mutation-free
     */
    public function identifier(): string
    {
        /** @var non-empty-string */
        return $this->path ?? $this->package ?? $this->url ?? '';
    }

    /**
     * Composite uniqueness key: `(from, host, path|package|url)`.
     * Used by the mapper to reject duplicate entries and by `skills:add`
     * to detect upsert vs insert. For `dir` entries the identifier is
     * the raw `path` string (`dir||./skills`) — two entries with the
     * same path are duplicates; the same directory reached via two
     * different spellings is not deduplicated (lexical identity only).
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
