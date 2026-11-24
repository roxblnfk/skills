<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;

/**
 * Reads a project config file at the project root — the shared
 * `skills.json` or the personal `skills.local.json` layered over it —
 * and returns its contents as an array shaped like the inline
 * `extra.skills` block, ready to be handed to the per-field validators
 * in {@see ProjectConfigMapper}.
 *
 * Both files share one shape. The file is **strict**: any unknown
 * top-level key fails the load.
 * `$schema` is the single exception — it is allowed (for IDE/editor
 * support) and silently stripped from the returned array so it does
 * not look like an unrecognised field downstream.
 *
 * Errors are surfaced as {@see MalformedProjectConfig} with messages
 * prefixed by the file name (`skills.json:`, `skills.local.json:`) so
 * the origin of the failure is clear in combined output.
 *
 * Not annotated `@psalm-immutable`: {@see self::load()} reads the
 * filesystem and is therefore impure. The class is otherwise
 * stateless and safe to share.
 */
final class ExternalProjectConfigLoader
{
    /**
     * Canonical filename. The loader looks for exactly this name at the
     * project root — there is no upward walk and no configurable
     * location at read time (the `--path` flag of `skills:init` only
     * affects file creation, not where subsequent commands look).
     */
    public const FILE_NAME = 'skills.json';

    /**
     * Personal, git-ignored config layered over {@see self::FILE_NAME}.
     * Looked up at the project root exactly like the shared file.
     */
    public const LOCAL_FILE_NAME = 'skills.local.json';

    /**
     * Top-level keys accepted inside `skills.json`. Mirrors the project
     * keys understood under `extra.skills`, plus the editor-only
     * `$schema` annotation and the keys only `skills.json` carries
     * ({@see ProjectConfigMapper::SKILLS_JSON_ONLY_KEYS}).
     *
     * Kept in sync with the per-field mapping in
     * {@see ProjectConfigMapper::mapSkillsBlock()} and with
     * `resources/skills.schema.json`.
     */
    public const ALLOWED_KEYS = [
        '$schema',
        'target',
        'aliases',
        'dependencies',
        'trusted',
        'trusted-replace',
        'discovery',
        'auto-sync',
        'path-from-root',
        'local',
        'vendor-sources',
        'remote',
        ...ProjectConfigMapper::SKILLS_JSON_ONLY_KEYS,
    ];

    /**
     * Load a config file from the given project root.
     *
     * Returns the decoded array (with `$schema` already stripped) when
     * the file exists, or `null` when it does not — for `skills.json`
     * the caller then falls back to the inline `extra.skills` block.
     *
     * @param non-empty-string $fileName {@see self::FILE_NAME} or {@see self::LOCAL_FILE_NAME}
     *
     * @return array<string, mixed>|null
     *
     * @throws MalformedProjectConfig when the file exists but cannot be read,
     *         contains invalid JSON, has a non-object root, or carries an
     *         unknown top-level key
     */
    public function load(Path $projectRoot, string $fileName = self::FILE_NAME): ?array
    {
        $filePath = (string) $projectRoot->join($fileName);
        if (!\is_file($filePath)) {
            return null;
        }

        $content = \file_get_contents($filePath);
        if ($content === false) {
            throw new MalformedProjectConfig(\sprintf(
                '%s: failed to read "%s"',
                $fileName,
                $filePath,
            ));
        }

        // Decode twice: once with `assoc=false` to catch root-shape
        // mistakes (an empty JSON list `[]` would otherwise be
        // indistinguishable from an empty JSON object `{}` after
        // assoc=true), and once with `assoc=true` to get the
        // recursive-array structure the mapper expects.
        //
        // The second decode is cheap on a config file (typically
        // <2 KiB) and avoids manual recursive `(array) $stdClass`
        // unpacking for nested map-shaped fields like `local`.
        try {
            /** @var mixed $typed */
            $typed = \json_decode($content, false, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MalformedProjectConfig(\sprintf(
                '%s: invalid JSON — %s',
                $fileName,
                $e->getMessage(),
            ));
        }

        if (!$typed instanceof \stdClass) {
            throw new MalformedProjectConfig($fileName . ': root must be a JSON object');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        // Casting a stdClass to array always yields string keys, so no
        // runtime is_string guard is needed.
        foreach ($decoded as $key => $_unused) {
            if (!\in_array($key, self::ALLOWED_KEYS, true)) {
                throw new MalformedProjectConfig(\sprintf(
                    '%s: unknown top-level key "%s"; allowed keys: %s',
                    $fileName,
                    $key,
                    \implode(', ', \array_filter(self::ALLOWED_KEYS, static fn(string $k): bool => $k !== '$schema')),
                ));
            }
        }

        // `$schema` is metadata for editors only; strip it so downstream
        // code never has to know about it.
        unset($decoded['$schema']);

        return $decoded;
    }
}
