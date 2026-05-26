<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

/**
 * Parameters for `skills:add`, built from the CLI surface.
 *
 * Models the user input verbatim — the adapter that handles the
 * `from` value owns the parsing rules for `$input`. The runner
 * dispatches by `$from`; when null, the adapter is inferred from the
 * input shape (URL with `github.com` ⇒ `github`, etc.).
 *
 * @psalm-immutable
 */
final readonly class AddOptions
{
    /**
     * @param non-empty-string $input adapter-specific argument: `owner/repo`,
     *         `owner/repo@ref`, or a full URL
     * @param non-empty-string|null $from adapter id; null means "infer from input"
     * @param non-empty-string|null $host registry / API host override (private Packagist,
     *         GHE, self-hosted GitLab)
     * @param non-empty-string|null $ref version pin override; null means "let the
     *         adapter pick the latest stable tag (or default branch HEAD) and
     *         store a `^X.Y.Z` caret only when a stable semver tag was chosen"
     * @param bool $sync when true (default), run a single-entry sync after the add so
     *         the new skills land in the target immediately — matches `composer require`'s
     *         "edit + install" ergonomics. When false, only the manifest is updated.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $input,
        public ?string $from = null,
        public ?string $host = null,
        public ?string $ref = null,
        public bool $sync = true,
    ) {}
}
