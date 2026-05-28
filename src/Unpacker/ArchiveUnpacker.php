<?php

declare(strict_types=1);

namespace LLM\Skills\Unpacker;

/**
 * Pluggable zip-extraction strategy.
 *
 * The fetcher uses one implementation per fetch: `\ZipArchive`-backed
 * when ext-zip is available, otherwise a CLI tool wrapper. Two-step
 * contract (`listEntries` → `extractTo`) is deliberate: it lets the
 * fetcher run a lexical zip-slip check against every entry name
 * **before** the underlying tool writes any byte to disk. CLI tools
 * (`unzip`, `7z`) do not expose a hook for that validation, so we own
 * the safety boundary at this seam instead.
 *
 * Implementations are not required to be pure — extraction is an
 * intentional filesystem effect.
 *
 * @psalm-suppress MissingInterfaceImmutableAnnotation
 *         I/O is intentional; concrete classes carry their own suppression
 */
interface ArchiveUnpacker
{
    /**
     * Short id surfaced in error messages — e.g. `ziparchive`, `unzip`,
     * `7z`. Used only for human-readable diagnostics.
     *
     * @return non-empty-string
     *
     * @psalm-mutation-free
     */
    public function id(): string;

    /**
     * Enumerate entry names from the archive without writing anything
     * to disk. Implementations must throw {@see UnpackerException} on
     * malformed input.
     *
     * @param non-empty-string $zipPath absolute path to a `.zip` file
     *
     * @return list<string> raw entry names exactly as encoded in the archive
     *
     * @psalm-impure
     */
    public function listEntries(string $zipPath): array;

    /**
     * Extract every entry into `$targetDir` preserving the archive's
     * internal directory structure. The directory is expected to exist
     * and be writable.
     *
     * @param non-empty-string $zipPath absolute path to a `.zip` file
     * @param non-empty-string $targetDir absolute path of an existing scratch directory
     *
     * @throws UnpackerException when the underlying tool fails
     *
     * @psalm-impure
     */
    public function extractTo(string $zipPath, string $targetDir): void;
}
