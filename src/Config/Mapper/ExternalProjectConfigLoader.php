<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;

/**
 * Reads the external `skills.json` file at the project root and returns
 * its contents as an array shaped like the inline `extra.skills` block,
 * ready to be handed to the per-field validators in
 * {@see ProjectConfigMapper}.
 *
 * The file is **strict**: any unknown top-level key fails the load.
 * `$schema` is the single exception — it is allowed (for IDE/editor
 * support) and silently stripped from the returned array so it does
 * not look like an unrecognised field downstream.
 *
 * Errors are surfaced as {@see MalformedProjectConfig} with messages
 * prefixed `skills.json:` so the origin of the failure is clear in
 * combined output.
 *
 * Not annotated `@psalm-immutable`: {@see self::load()} reads the
 * filesystem and is therefore impure. The class is otherwise
 * stateless and safe to share.
 */
final readonly class ExternalProjectConfigLoader
{
    /**
     * Canonical filename. The loader looks for exactly this name at the
     * project root — there is no upward walk and no configurable
     * location at read time (the `--path` flag of `skills:init` only
     * affects file creation, not where subsequent commands look).
     */
    public const FILE_NAME = 'skills.json';

    /**
     * Top-level keys accepted inside `skills.json`. Mirrors the project
     * keys understood under `extra.skills`, plus the editor-only
     * `$schema` annotation.
     *
     * Kept in sync with the per-field mapping in
     * {@see ProjectConfigMapper::mapSkillsBlock()} and with
     * `resources/skills.schema.json`.
     */
    public const ALLOWED_KEYS = [
        '$schema',
        'target',
        'aliases',
        'trusted',
        'trusted-replace',
        'discovery',
        'auto-sync',
    ];

    /**
     * Load `skills.json` from the given project root.
     *
     * Returns the decoded array (with `$schema` already stripped) when
     * the file exists, or `null` when it does not — the caller then
     * falls back to the inline `extra.skills` block.
     *
     * @return array<string, mixed>|null
     *
     * @throws MalformedProjectConfig when the file exists but cannot be read,
     *         contains invalid JSON, has a non-object root, or carries an
     *         unknown top-level key
     */
    public function load(Path $projectRoot): ?array
    {
        $filePath = (string) $projectRoot->join(self::FILE_NAME);
        if (!\is_file($filePath)) {
            return null;
        }

        $content = \file_get_contents($filePath);
        if ($content === false) {
            throw new MalformedProjectConfig(\sprintf(
                'skills.json: failed to read "%s"',
                $filePath,
            ));
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MalformedProjectConfig(\sprintf(
                'skills.json: invalid JSON — %s',
                $e->getMessage(),
            ));
        }

        if (!\is_array($decoded)) {
            throw new MalformedProjectConfig('skills.json: root must be a JSON object');
        }

        // A non-empty list (`[1, 2, 3]`) decodes to an array but is not
        // an object — distinguish it from `{}` which decodes to `[]`.
        if ($decoded !== [] && \array_is_list($decoded)) {
            throw new MalformedProjectConfig('skills.json: root must be a JSON object, got a list');
        }

        foreach ($decoded as $key => $_unused) {
            if (!\is_string($key) || !\in_array($key, self::ALLOWED_KEYS, true)) {
                throw new MalformedProjectConfig(\sprintf(
                    'skills.json: unknown top-level key "%s"; allowed keys: %s',
                    \is_string($key) ? $key : '(non-string)',
                    \implode(', ', \array_filter(self::ALLOWED_KEYS, static fn(string $k): bool => $k !== '$schema')),
                ));
            }
        }

        // `$schema` is metadata for editors only; strip it so downstream
        // code never has to know about it.
        unset($decoded['$schema']);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
