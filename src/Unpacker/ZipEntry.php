<?php

declare(strict_types=1);

namespace LLM\Skills\Unpacker;

/**
 * One Central Directory entry, reduced to the two facts the extraction
 * pipeline acts on: the raw name and whether it is a symbolic link.
 *
 * @psalm-immutable
 */
final readonly class ZipEntry
{
    /**
     * @param string $name raw entry name exactly as encoded in the archive —
     *        no decoding, no separator normalisation. The zip-slip check and the
     *        CLI exclusion switches both need the archive's own spelling.
     * @param bool $isSymlink entry stores a symlink target rather than file content.
     *        Only ever true for archives written by a Unix-family host: the flag is
     *        derived from the Unix mode in `external_attr`, which MS-DOS-host
     *        archives do not carry. See {@see ZipCentralDirectoryReader}.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $name,
        public bool $isSymlink,
    ) {}
}
