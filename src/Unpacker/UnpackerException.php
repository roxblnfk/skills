<?php

declare(strict_types=1);

namespace LLM\Skills\Unpacker;

/**
 * Unpacker-level failure — bubble up to the fetcher, which wraps it
 * into a {@see \LLM\Skills\Discovery\Provider\Remote\RemoteFetchException}
 * with full ref context.
 *
 * Two shapes:
 *
 * - listing failure (malformed archive, zip64 sentinel, truncated CDR);
 * - extraction failure (CLI tool returned non-zero, ZipArchive errored).
 */
final class UnpackerException extends \RuntimeException {}
