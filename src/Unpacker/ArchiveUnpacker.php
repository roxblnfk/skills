<?php

declare(strict_types=1);

namespace LLM\Skills\Unpacker;

/**
 * Pluggable zip-extraction strategy.
 *
 * The fetcher uses one implementation per fetch: `\ZipArchive`-backed
 * when ext-zip is available, otherwise a CLI tool wrapper. Two-step
 * contract (`listEntries` → `extractTo`) is deliberate: it lets the
 * fetcher inspect every entry **before** the underlying tool writes any
 * byte to disk — running the lexical zip-slip check and deciding which
 * entries to exclude from the extraction. CLI tools (`unzip`, `7z`) do
 * not expose a hook for that validation, so we own the safety boundary
 * at this seam instead. Keeping the decision in the fetcher also keeps
 * both implementations producing the same tree for the same archive:
 * per-unpacker code only translates the exclusion list into its tool's
 * vocabulary.
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
     * Enumerate archive entries without writing anything to disk.
     * Implementations must throw {@see UnpackerException} on malformed
     * input.
     *
     * @param non-empty-string $zipPath absolute path to a `.zip` file
     *
     * @return list<ZipEntry> entries with raw names exactly as encoded in the archive
     *
     * @psalm-impure
     */
    public function listEntries(string $zipPath): array;

    /**
     * Extract the archive into `$targetDir` preserving its internal
     * directory structure. The directory is expected to exist and be
     * writable.
     *
     * Entries named in `$excludeNames` are left unextracted. Exclusion
     * is best-effort at the tool boundary — a CLI tool may be unable to
     * express some names safely (see {@see CliUnpacker}) — so callers
     * must tolerate an excluded entry occasionally reaching the target
     * tree anyway.
     *
     * @param non-empty-string $zipPath absolute path to a `.zip` file
     * @param non-empty-string $targetDir absolute path of an existing scratch directory
     * @param list<string> $excludeNames raw entry names, as returned by
     *        {@see self::listEntries()}, to leave unextracted
     *
     * @throws UnpackerException when the underlying tool fails
     *
     * @psalm-impure
     */
    public function extractTo(string $zipPath, string $targetDir, array $excludeNames = []): void;
}
