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
     * `version_made_by` high byte for the Unix host (APPNOTE.TXT
     * §4.4.2.2, value 3).
     */
    public const HOST_UNIX = 3;

    /**
     * `version_made_by` high byte for the OS X / Darwin host
     * (APPNOTE.TXT §4.4.2.2, value 19). Darwin is a Unix family member
     * and stores the same `st_mode` layout in `external_attr`.
     */
    public const HOST_DARWIN = 19;

    /** Mask and value selecting `S_IFLNK` out of a Unix `st_mode`. */
    private const S_IFMT = 0xF000;

    private const S_IFLNK = 0xA000;

    /**
     * @param string $name raw entry name exactly as encoded in the archive —
     *        no decoding, no separator normalisation. The zip-slip check and the
     *        CLI exclusion switches both need the archive's own spelling.
     * @param bool $isSymlink entry stores a symlink target rather than file content.
     *        Only ever true for archives written by a Unix-family host: the flag is
     *        derived from the Unix mode in `external_attr`, which MS-DOS-host
     *        archives do not carry. See {@see self::isSymlinkAttributes()}.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $name,
        public bool $isSymlink,
    ) {}

    /**
     * Decide whether Central Directory attributes describe a symlink.
     *
     * The Unix mode lives in the high 16 bits of `external_attr`, but
     * only when the entry was written by a Unix-family host. On an
     * MS-DOS-host entry those bits are unspecified (the low bits are
     * DOS attribute flags), so reading a mode out of them would
     * classify arbitrary entries as links.
     *
     * `$hostByte` is APPNOTE.TXT §4.4.2.2's "version made by" high
     * byte. `\ZipArchive::getExternalAttributesIndex()` yields the
     * same value as its `$opsys` out-parameter, so both the raw
     * Central Directory reader and the ext-zip path can share this
     * single definition.
     *
     * @psalm-pure
     */
    public static function isSymlinkAttributes(int $hostByte, int $externalAttr): bool
    {
        if ($hostByte !== self::HOST_UNIX && $hostByte !== self::HOST_DARWIN) {
            return false;
        }

        return (($externalAttr >> 16) & self::S_IFMT) === self::S_IFLNK;
    }
}
