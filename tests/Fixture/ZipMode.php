<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Fixture;

/**
 * Unix `st_mode` values for the high half of a zip entry's `external_attr`,
 * encoded exactly as `git archive` and GitHub's zipball endpoint store them.
 */
final class ZipMode
{
    /** A symlink entry: `S_IFLNK | 0777`. */
    public const SYMLINK = 0xA1FF;

    /** A regular file entry: `S_IFREG | 0644`. */
    public const REGULAR = 0x81A4;
}
