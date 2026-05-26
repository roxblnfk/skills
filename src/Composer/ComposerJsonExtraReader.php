<?php

declare(strict_types=1);

namespace LLM\Skills\Composer;

use Composer\IO\IOInterface;
use Internal\Path;

/**
 * Best-effort reader for the `extra` field of a project's `composer.json`.
 *
 * Used by the standalone `bin/skills` entrypoints
 * ({@see \LLM\Skills\Console\Command\Sync},
 * {@see \LLM\Skills\Console\Command\Show}) as a fallback when
 * {@see \Composer\Factory::create()} threw but `composer.json` is still
 * parseable. Without this fallback, the legacy inline `extra.skills`
 * config would silently disappear for any project where Composer
 * bootstrap fails (locked vendor tree, schema mismatch, unreadable
 * subfile, etc.) — leaving the runner with defaults even though the
 * user's intent is one `file_get_contents` away.
 *
 * The reader is intentionally permissive: every error path is folded
 * into a `null` return, because the caller has no recovery available.
 * Diagnostic noise goes to the IO under `-v` so a curious user can
 * see *why* the fallback came up empty.
 *
 * Composer-attached entrypoints (the plugin commands and the auto-sync
 * hook) do NOT need this — they already hold a live `Composer` instance
 * and pull extras directly from `$composer->getPackage()->getExtra()`.
 */
final readonly class ComposerJsonExtraReader
{
    /**
     * Read `<projectRoot>/composer.json` and return its top-level `extra`
     * value. Returns `null` when the file does not exist, is unreadable,
     * is not valid JSON, decodes to something other than an object, or
     * carries no `extra` field. Each non-trivial failure emits a `-v`
     * notice on `$io`.
     *
     * @return mixed the raw `extra` value (typically `array<string, mixed>`)
     *         or `null` when no extras are available
     */
    public function read(Path $projectRoot, IOInterface $io): mixed
    {
        $path = (string) $projectRoot->join('composer.json');
        if (!\is_file($path)) {
            return null;
        }

        $content = \file_get_contents($path);
        if ($content === false) {
            $io->writeError(
                \sprintf(
                    '<comment>[warn] composer.json present at %s but unreadable; '
                    . 'inline extra.skills fallback disabled.</comment>',
                    $path,
                ),
                verbosity: IOInterface::VERBOSE,
            );
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->writeError(
                '<comment>[warn] composer.json present but not valid JSON; '
                . 'inline extra.skills fallback disabled: ' . $e->getMessage() . '</comment>',
                verbosity: IOInterface::VERBOSE,
            );
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        /** @var mixed $extra */
        $extra = $decoded['extra'] ?? null;
        return $extra;
    }
}
