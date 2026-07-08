<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider;

/**
 * Vocabulary of donor-provider identifiers.
 *
 * Used in three places:
 *
 * - `skills.json` `local: { <id>: bool }` keys — which local providers
 *   are enabled in the current project.
 * - `skills.json` `sources[].from` values — which source adapter resolves
 *   a given remote entry.
 * - The `--from=<id>` CLI flag on `skills:update` (Phase 5).
 *
 * The vocabulary is locked up front so the format can accommodate future
 * adapters without a schema migration. The constants below are the
 * source of truth for the table of identifiers.
 *
 * @psalm-immutable
 */
final class ProviderId
{
    public const COMPOSER = 'composer';
    public const NPM = 'npm';
    public const GO = 'go';
    public const GITHUB = 'github';
    public const GITLAB = 'gitlab';
    public const BITBUCKET = 'bitbucket';
    public const SKILLS_SH = 'skills.sh';
    public const HTTP = 'http';
    public const ZIP = 'zip';
    public const DIR = 'dir';

    /**
     * Identifiers that may appear as keys under `local`. Today only
     * `composer` has an implementation; the others lock the vocabulary
     * so future providers ship without a migration.
     *
     * @var list<non-empty-string>
     */
    public const LOCAL_IDS = [
        self::COMPOSER,
        self::NPM,
        self::GO,
    ];

    /**
     * Identifiers that may appear as `from` values inside `sources[]`.
     * Larger than {@see LOCAL_IDS} because remote adapters cover both
     * VCS hosts (no local manifest) and package registries.
     *
     * @var list<non-empty-string>
     */
    public const REMOTE_IDS = [
        self::GITHUB,
        self::GITLAB,
        self::BITBUCKET,
        self::COMPOSER,
        self::NPM,
        self::GO,
        self::SKILLS_SH,
        self::HTTP,
        self::ZIP,
        self::DIR,
    ];

    /**
     * Adapters that identify the donor by URL only (no `package` field).
     *
     * @var list<non-empty-string>
     */
    public const URL_ONLY_REMOTE_IDS = [
        self::HTTP,
        self::ZIP,
    ];

    /**
     * Adapters that identify the donor by a filesystem `path` — neither
     * URL-only nor name-based. A path-only entry is an explicit local
     * directory declaration; it requires `path`, forbids `url`, and
     * treats `package` as an optional name override rather than the
     * identifier.
     *
     * @var list<non-empty-string>
     */
    public const PATH_ONLY_SOURCE_IDS = [
        self::DIR,
    ];

    /**
     * @psalm-pure
     */
    public static function isKnownLocal(string $id): bool
    {
        return \in_array($id, self::LOCAL_IDS, true);
    }

    /**
     * @psalm-pure
     */
    public static function isKnownRemote(string $id): bool
    {
        return \in_array($id, self::REMOTE_IDS, true);
    }

    /**
     * Whether the adapter identifies its donor by URL (rather than by
     * package name). URL-only adapters reject `package` and require
     * `url`; name-based adapters do the inverse.
     *
     * @psalm-pure
     */
    public static function isUrlOnlyRemote(string $id): bool
    {
        return \in_array($id, self::URL_ONLY_REMOTE_IDS, true);
    }

    /**
     * Whether the adapter identifies its donor by a filesystem `path`
     * (rather than by package name or URL). Path-only adapters require
     * `path`, reject `url`, and accept `package` only as an optional
     * name override.
     *
     * @psalm-pure
     */
    public static function isPathOnlySource(string $id): bool
    {
        return \in_array($id, self::PATH_ONLY_SOURCE_IDS, true);
    }

    /**
     * Default activation state for a local provider when the user has
     * not pinned it explicitly. `composer` defaults to enabled
     * (preserves the pre-`local` behaviour); every other provider
     * defaults to disabled so it stays opt-in until its implementation
     * lands.
     *
     * @psalm-pure
     */
    public static function defaultLocalEnabled(string $id): bool
    {
        return $id === self::COMPOSER;
    }
}
