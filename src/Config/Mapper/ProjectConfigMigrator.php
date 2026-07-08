<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

use Composer\IO\IOInterface;
use Composer\Json\JsonManipulator;
use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Filesystem\AtomicFileWriter;

/**
 * Moves a project's legacy inline `extra.skills` block out of
 * `composer.json` and into the canonical `skills.json` file.
 *
 * Triggered automatically by write-mode entrypoints
 * ({@see \LLM\Skills\Sync\SyncRunner},
 * {@see \LLM\Skills\Init\InitRunner}, and the `post-update-cmd`
 * auto-sync hook in {@see \LLM\Skills\Composer\SkillsPlugin}). Read-only
 * surfaces ({@see \LLM\Skills\Show\ShowRunner},
 * {@see \LLM\Skills\Composer\SkillsPlugin}'s `post-install-cmd` hook)
 * never call into this class — they emit a notice instead, leaving
 * the user in control of when their `composer.json` gets rewritten.
 *
 * The migration is **atomic in spirit**: validation happens before
 * any filesystem write, and the two writes (skills.json, then
 * composer.json) run in fixed order. If composer.json rewrite then
 * fails, the freshly-written skills.json plus an unmodified
 * composer.json is a recoverable state — a subsequent run sees
 * skills.json and skips migration entirely.
 *
 * Idempotent: when `skills.json` already exists, or no inline
 * project keys are present, the migrator returns
 * {@see MigrationStatus::Skipped} without touching anything.
 */
final readonly class ProjectConfigMigrator
{
    /**
     * URL of the published JSON schema. Emitted into the generated
     * file's `$schema` field so editors that follow the link can
     * offer validation / autocompletion.
     */
    public const SCHEMA_URL = 'https://raw.githubusercontent.com/roxblnfk/skills/master/resources/skills.schema.json';

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private ProjectConfigMapper $projectMapper = new ProjectConfigMapper(),
    ) {}

    /**
     * Render the file content for a new `skills.json`. Defaults are
     * NOT written — only the keys the user actually customised.
     * Always starts with a `$schema` pointer so editors can validate.
     *
     * @param array<non-empty-string, mixed> $migrated project keys in {@see ProjectConfigMapper::PROJECT_KEYS} order
     *
     * @psalm-pure
     */
    public static function renderSkillsJson(array $migrated): string
    {
        $payload = ['$schema' => self::SCHEMA_URL] + $migrated;

        $json = \json_encode(
            $payload,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        if ($json === false) {
            throw new \RuntimeException('Failed to encode skills.json payload');
        }

        return $json . "\n";
    }

    /**
     * Pull the project-level keys out of an inline `extra.skills`
     * block, in canonical order, as they should be written into
     * `skills.json`. Donor `source` and any unrelated keys are
     * deliberately dropped. Two families of deprecated keys are folded
     * into their canonical replacement so the written file never
     * carries an alias: a `remote` value becomes `sources`, and the
     * legacy trust trio (`local`, flat `trusted`, flat
     * `trusted-replace`) becomes a `dependencies` block. A block that
     * mixes an alias with its canonical key is rejected pre-flight by
     * the mapper, so at most one form of each pair is present here.
     *
     * @param array<array-key, mixed> $skills the inline `extra.skills` value
     *
     * @return array<non-empty-string, mixed>
     *
     * @psalm-pure
     */
    public static function extractProjectKeys(array $skills): array
    {
        $out = [];
        foreach (ProjectConfigMapper::PROJECT_KEYS as $key) {
            // Aliases never land in the output under their own name;
            // their values are picked up when the canonical key is
            // visited (`remote` → `sources`, the legacy trust trio →
            // `dependencies`).
            if (
                $key === ProjectConfigMapper::DEPRECATED_SOURCES_KEY
                || \in_array($key, ProjectConfigMapper::DEPRECATED_DEPENDENCY_KEYS, true)
            ) {
                continue;
            }
            if ($key === ProjectConfigMapper::DEPENDENCIES_KEY) {
                if (\array_key_exists($key, $skills)) {
                    /** @psalm-suppress MixedAssignment value type intentionally unknown until mapper validates */
                    $out[$key] = $skills[$key];
                } elseif (self::hasLegacyDependencyKeys($skills)) {
                    $out[$key] = self::foldLegacyDependencies($skills);
                }
                continue;
            }
            if (\array_key_exists($key, $skills)) {
                /** @psalm-suppress MixedAssignment value type intentionally unknown until mapper validates */
                $out[$key] = $skills[$key];
                continue;
            }
            if (
                $key === ProjectConfigMapper::SOURCES_KEY
                && \array_key_exists(ProjectConfigMapper::DEPRECATED_SOURCES_KEY, $skills)
            ) {
                /** @psalm-suppress MixedAssignment value type intentionally unknown until mapper validates */
                $out[$key] = $skills[ProjectConfigMapper::DEPRECATED_SOURCES_KEY];
            }
        }

        return $out;
    }

    /**
     * Whether the block carries any of the legacy trust keys folded
     * into `dependencies` (`trusted`, `trusted-replace`, `local`).
     *
     * @param array<array-key, mixed> $block a `skills.json` / `extra.skills` block
     *
     * @psalm-pure
     */
    public static function hasLegacyDependencyKeys(array $block): bool
    {
        foreach (ProjectConfigMapper::DEPRECATED_DEPENDENCY_KEYS as $key) {
            if (\array_key_exists($key, $block)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fold the legacy trust trio into a `dependencies` block. Starts
     * from the `local` map verbatim, then upgrades the `composer` entry
     * to object form when a flat `trusted` / `trusted-replace` value is
     * present, carrying an explicit `local.composer` bool across as the
     * object's `enabled`. Only keys the user actually wrote are emitted:
     * an absent `enabled` or `trusted-replace` is not materialised as
     * its default.
     *
     * Values are copied verbatim, not validated — the mapper enforces
     * the shape after migration exactly as it would for a hand-written
     * `dependencies` block.
     *
     * @param array<array-key, mixed> $block a `skills.json` / `extra.skills` block
     *
     * @return array<string, mixed>
     *
     * @psalm-pure
     */
    public static function foldLegacyDependencies(array $block): array
    {
        /** @var mixed $local */
        $local = $block['local'] ?? null;
        $hasTrusted = \array_key_exists('trusted', $block);
        $hasReplace = \array_key_exists('trusted-replace', $block);

        $dependencies = [];
        if (\is_array($local)) {
            /** @var mixed $enabled */
            foreach ($local as $id => $enabled) {
                /** @psalm-suppress MixedAssignment value copied verbatim; mapper validates */
                $dependencies[(string) $id] = $enabled;
            }
        }

        if ($hasTrusted || $hasReplace) {
            $composer = [];
            if (\is_array($local) && \array_key_exists('composer', $local)) {
                /** @psalm-suppress MixedAssignment */
                $composer['enabled'] = $local['composer'];
            }
            if ($hasTrusted) {
                /** @psalm-suppress MixedAssignment */
                $composer['trusted'] = $block['trusted'];
            }
            if ($hasReplace) {
                /** @psalm-suppress MixedAssignment */
                $composer['trusted-replace'] = $block['trusted-replace'];
            }
            $dependencies['composer'] = $composer;
        }

        return $dependencies;
    }

    /**
     * List the project-level keys physically present in an inline
     * `extra.skills` block, in canonical order and under their
     * original names (the deprecated `remote` alias is reported as
     * `remote`). Callers use this to strip the exact keys from
     * `composer.json`, which {@see self::extractProjectKeys()} cannot
     * provide because it rewrites the alias to `sources`.
     *
     * @param array<array-key, mixed> $skills the inline `extra.skills` value
     *
     * @return list<non-empty-string>
     *
     * @psalm-pure
     */
    public static function presentProjectKeys(array $skills): array
    {
        $out = [];
        foreach (ProjectConfigMapper::PROJECT_KEYS as $key) {
            if (\array_key_exists($key, $skills)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Detect-and-migrate. Returns:
     *
     * - {@see MigrationStatus::Skipped} when `skills.json` already
     *   exists, when there is no `composer.json` at all (standalone
     *   project — nothing to migrate from), or when `extra.skills`
     *   has no project-level keys.
     * - {@see MigrationStatus::Migrated} after writing `skills.json`
     *   and stripping the migrated keys from `composer.json`.
     * - {@see MigrationStatus::Failed} when migration was eligible
     *   but a step errored out; the caller surfaces this as a
     *   non-zero exit code (error already emitted on `$io`).
     */
    public function migrate(Path $projectRoot, IOInterface $io): MigrationResult
    {
        $skillsJsonPath = (string) $projectRoot->join('skills.json');
        if (\is_file($skillsJsonPath)) {
            return MigrationResult::skipped();
        }

        $composerJsonPath = (string) $projectRoot->join('composer.json');
        if (!\is_file($composerJsonPath)) {
            return MigrationResult::skipped();
        }

        $original = \file_get_contents($composerJsonPath);
        if ($original === false) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] failed to read composer.json at %s</error>',
                $composerJsonPath,
            ));
            return MigrationResult::failed();
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($original, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] composer.json is not valid JSON: %s</error>',
                $e->getMessage(),
            ));
            return MigrationResult::failed();
        }

        if (!\is_array($decoded)) {
            $io->writeError('<error>[llm/skills] composer.json must be a JSON object</error>');
            return MigrationResult::failed();
        }

        /** @var array<string, mixed> $extra */
        $extra = \is_array($decoded['extra'] ?? null) ? $decoded['extra'] : [];
        /** @var array<string, mixed> $skills */
        $skills = \is_array($extra['skills'] ?? null) ? $extra['skills'] : [];

        // Keys as written to skills.json (alias folded into `sources`)
        // versus keys as they physically sit in composer.json (used to
        // strip the originals). The two diverge only for a `remote`
        // block.
        $migrated = self::extractProjectKeys($skills);
        $presentKeys = self::presentProjectKeys($skills);
        if ($presentKeys === []) {
            // composer.json exists but carries no inline project keys
            // (maybe just a donor `source`, or no skills block at all).
            // Nothing to migrate — and we deliberately do NOT auto-create
            // a stub skills.json here, because we'd be writing a file
            // the user never asked for.
            return MigrationResult::skipped();
        }

        // Pre-flight validation: refuse to relocate a malformed config.
        try {
            $this->projectMapper->fromExtra($extra);
        } catch (MalformedProjectConfig $e) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] cannot auto-migrate: inline extra.skills is malformed — %s. '
                . 'Fix composer.json or remove the inline block, then re-run.</error>',
                $e->getMessage(),
            ));
            return MigrationResult::failed();
        }

        // Write skills.json first. If composer.json rewrite fails, the
        // next run sees skills.json and skips migration entirely — the
        // user just has to clean up their composer.json by hand.
        $content = self::renderSkillsJson($migrated);
        if (\file_put_contents($skillsJsonPath, $content) === false) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] failed to write %s</error>',
                $skillsJsonPath,
            ));
            return MigrationResult::failed();
        }

        $manipulator = new JsonManipulator($original);
        foreach ($presentKeys as $key) {
            if (!$manipulator->removeSubNode('extra', 'skills.' . $key)) {
                $io->writeError(\sprintf(
                    '<error>[llm/skills] failed to remove extra.skills.%s from composer.json '
                    . '(skills.json was written; clean up composer.json by hand).</error>',
                    $key,
                ));
                return MigrationResult::failed();
            }
        }

        // If `extra.skills` had nothing but the migrated project keys,
        // it is now an empty object — strip it so composer.json doesn't
        // accumulate dead `"skills": {}` debris. Same hygiene for
        // `extra` itself when it becomes empty (the user might not have
        // anything else under it). Donor-side `source` and any other
        // unrelated `extra.skills` keys keep both nodes alive.
        $remaining = \array_diff(\array_keys($skills), $presentKeys);
        if ($remaining === []) {
            $manipulator->removeSubNode('extra', 'skills');
            $manipulator->removeMainKeyIfEmpty('extra');
        }

        if (\file_put_contents($composerJsonPath, $manipulator->getContents()) === false) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] failed to write composer.json at %s</error>',
                $composerJsonPath,
            ));
            return MigrationResult::failed();
        }

        return MigrationResult::migrated(\array_keys($migrated));
    }

    /**
     * Rename the deprecated `remote` key to `sources` in an existing
     * `skills.json`, in place. Returns:
     *
     * - {@see MigrationStatus::Skipped} when there is no `skills.json`,
     *   when it already uses `sources` (or uses neither key), or when
     *   it carries BOTH keys — the last case is left untouched so the
     *   mapper's fatal "both present" error stands rather than the
     *   migration guessing which list wins. Malformed JSON is also
     *   skipped, letting {@see ExternalProjectConfigLoader} surface the
     *   precise parse error during mapping.
     * - {@see MigrationStatus::Migrated} after rewriting the file with
     *   the key renamed, every other key and its position preserved.
     * - {@see MigrationStatus::Failed} when the file exists but a read,
     *   encode, or write step errored out (message already on `$io`).
     */
    public function renameSourcesKey(Path $projectRoot, IOInterface $io): MigrationResult
    {
        $skillsJsonPath = (string) $projectRoot->join(ExternalProjectConfigLoader::FILE_NAME);
        if (!\is_file($skillsJsonPath)) {
            return MigrationResult::skipped();
        }

        $original = \file_get_contents($skillsJsonPath);
        if ($original === false) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] failed to read skills.json at %s</error>',
                $skillsJsonPath,
            ));
            return MigrationResult::failed();
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($original, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return MigrationResult::skipped();
        }

        if (!\is_array($decoded)) {
            return MigrationResult::skipped();
        }

        $hasDeprecated = \array_key_exists(ProjectConfigMapper::DEPRECATED_SOURCES_KEY, $decoded);
        $hasCanonical = \array_key_exists(ProjectConfigMapper::SOURCES_KEY, $decoded);
        if (!$hasDeprecated || $hasCanonical) {
            return MigrationResult::skipped();
        }

        // Rebuild the map so the renamed key keeps its original slot and
        // every sibling key stays verbatim.
        $rebuilt = [];
        /** @var mixed $value */
        foreach ($decoded as $key => $value) {
            $target = $key === ProjectConfigMapper::DEPRECATED_SOURCES_KEY
                ? ProjectConfigMapper::SOURCES_KEY
                : $key;
            /** @psalm-suppress MixedAssignment value type is validated later by the mapper */
            $rebuilt[$target] = $value;
        }

        $json = \json_encode(
            $rebuilt,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        if ($json === false) {
            $io->writeError('<error>[llm/skills] failed to encode skills.json during key rename</error>');
            return MigrationResult::failed();
        }

        try {
            AtomicFileWriter::write($skillsJsonPath, $json . "\n");
        } catch (\RuntimeException $e) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] failed to write skills.json during key rename: %s</error>',
                $e->getMessage(),
            ));
            return MigrationResult::failed();
        }

        $io->write(\sprintf(
            '<info>[migrate]</info> renamed "%s" to "%s" in skills.json',
            ProjectConfigMapper::DEPRECATED_SOURCES_KEY,
            ProjectConfigMapper::SOURCES_KEY,
        ));

        return MigrationResult::migrated([ProjectConfigMapper::SOURCES_KEY]);
    }

    /**
     * Restructure the legacy trust trio (`local`, flat `trusted`, flat
     * `trusted-replace`) into a single `dependencies` block in an
     * existing `skills.json`, in place. Runs next to
     * {@see self::renameSourcesKey()} on the write-mode entrypoints and
     * after it, so a fully-legacy file gets both fixes in one run.
     * Returns:
     *
     * - {@see MigrationStatus::Skipped} when there is no `skills.json`,
     *   when `dependencies` is already present (new form, or an illegal
     *   mix with legacy keys the mapper will reject — migration must not
     *   guess which form wins), when no legacy key is present, or when
     *   `local` is present but not an object (it cannot be transformed
     *   into a per-manager map without dropping data; the mapper reports
     *   the shape error on the original file instead). Malformed JSON is
     *   skipped too, letting {@see ExternalProjectConfigLoader} surface
     *   the parse error during mapping.
     * - {@see MigrationStatus::Migrated} after rewriting the file with
     *   `dependencies` at the slot of the first legacy key, every other
     *   key and its position preserved and the remaining legacy keys
     *   dropped.
     * - {@see MigrationStatus::Failed} when the file exists but a read,
     *   encode, or write step errored out (message already on `$io`).
     */
    public function restructureDependencies(Path $projectRoot, IOInterface $io): MigrationResult
    {
        $skillsJsonPath = (string) $projectRoot->join(ExternalProjectConfigLoader::FILE_NAME);
        if (!\is_file($skillsJsonPath)) {
            return MigrationResult::skipped();
        }

        $original = \file_get_contents($skillsJsonPath);
        if ($original === false) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] failed to read skills.json at %s</error>',
                $skillsJsonPath,
            ));
            return MigrationResult::failed();
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($original, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return MigrationResult::skipped();
        }

        if (!\is_array($decoded)) {
            return MigrationResult::skipped();
        }

        // Already on the canonical key — a new-form file, or an illegal
        // mix of `dependencies` with a legacy key. Either way, leave it:
        // the mapper's fatal "both present" check must stand rather than
        // the migration guessing which trust surface wins.
        if (\array_key_exists(ProjectConfigMapper::DEPENDENCIES_KEY, $decoded)) {
            return MigrationResult::skipped();
        }

        $found = [];
        foreach (ProjectConfigMapper::DEPRECATED_DEPENDENCY_KEYS as $key) {
            if (\array_key_exists($key, $decoded)) {
                $found[] = $key;
            }
        }
        if ($found === []) {
            return MigrationResult::skipped();
        }

        // A non-object `local` cannot fold into a per-manager map
        // without discarding it; leave the file untouched so the mapper
        // reports the shape error on the original rather than migration
        // dropping data.
        if (\array_key_exists('local', $decoded) && !\is_array($decoded['local'])) {
            return MigrationResult::skipped();
        }

        $dependencies = self::foldLegacyDependencies($decoded);

        // Rebuild so `dependencies` takes the slot of the first legacy
        // key encountered and every sibling key keeps its position; the
        // remaining legacy keys drop out.
        $rebuilt = [];
        $placed = false;
        /** @var mixed $value */
        foreach ($decoded as $key => $value) {
            if (\in_array($key, ProjectConfigMapper::DEPRECATED_DEPENDENCY_KEYS, true)) {
                if (!$placed) {
                    $rebuilt[ProjectConfigMapper::DEPENDENCIES_KEY] = $dependencies;
                    $placed = true;
                }
                continue;
            }
            /** @psalm-suppress MixedAssignment value type is validated later by the mapper */
            $rebuilt[$key] = $value;
        }

        $json = \json_encode(
            $rebuilt,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        if ($json === false) {
            $io->writeError('<error>[llm/skills] failed to encode skills.json during dependency restructure</error>');
            return MigrationResult::failed();
        }

        try {
            AtomicFileWriter::write($skillsJsonPath, $json . "\n");
        } catch (\RuntimeException $e) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] failed to write skills.json during dependency restructure: %s</error>',
                $e->getMessage(),
            ));
            return MigrationResult::failed();
        }

        $io->write(\sprintf(
            '<info>[migrate]</info> restructured %s into "%s" in skills.json',
            \implode(', ', \array_map(static fn(string $k): string => '"' . $k . '"', $found)),
            ProjectConfigMapper::DEPENDENCIES_KEY,
        ));

        return MigrationResult::migrated([ProjectConfigMapper::DEPENDENCIES_KEY]);
    }
}
