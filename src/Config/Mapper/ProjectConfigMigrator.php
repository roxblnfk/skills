<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

use Composer\IO\IOInterface;
use Composer\Json\JsonManipulator;
use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;

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
     * block, in canonical order. Donor `source` and any unrelated
     * keys are deliberately dropped.
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
            if (\array_key_exists($key, $skills)) {
                /** @psalm-suppress MixedAssignment value type intentionally unknown until mapper validates */
                $out[$key] = $skills[$key];
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

        $migrated = self::extractProjectKeys($skills);
        if ($migrated === []) {
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
        foreach (\array_keys($migrated) as $key) {
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
        $remaining = \array_diff(\array_keys($skills), \array_keys($migrated));
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
}
