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
final class AddOptions
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
     * @param list<non-empty-string>|null $skills explicit skill-name allowlist from
     *         `--skill=NAME` (repeatable). `null` means the flag was not used and the
     *         resulting entry inherits the legacy "sync every skill" default; a non-empty
     *         list is propagated into the entry's `skills` field and merged with any
     *         pre-existing names when the entry already exists.
     * @param bool $local when true, the entry is written to the personal `skills.local.json`
     *         instead of the shared `skills.json`
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly string $input,
        public readonly ?string $from = null,
        public readonly ?string $host = null,
        public readonly ?string $ref = null,
        public readonly bool $sync = true,
        public readonly ?array $skills = null,
        public readonly bool $local = false,
    ) {}

    /**
     * Name of the config file the entry is written to.
     *
     * @return non-empty-string
     *
     * @psalm-mutation-free
     */
    public function configFile(): string
    {
        return $this->local
            ? Mapper\ExternalProjectConfigLoader::LOCAL_FILE_NAME
            : Mapper\ExternalProjectConfigLoader::FILE_NAME;
    }
}
