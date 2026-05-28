<?php

declare(strict_types=1);

namespace LLM\Skills\Unpacker;

/**
 * Minimal zip Central Directory parser — enumerates entry names from
 * a `.zip` file **without** depending on `ext-zip`.
 *
 * Used by the CLI unpacker to validate every entry name lexically
 * (zip-slip guard) before invoking `unzip` / `7z`. The CLI tools do
 * not expose a pre-extraction validation hook and apply `-y`-style
 * overwrite semantics by default, so the only safe place to reject a
 * traversal entry is *here*, before any byte hits disk.
 *
 * Scope:
 *
 * - reads the End-of-Central-Directory record by scanning back from
 *   EOF (signature `PK\x05\x06`, comment up to 65 535 bytes);
 * - walks the Central Directory file headers (signature `PK\x01\x02`),
 *   reading only the file-name field;
 * - fails loud on zip64 sentinels — supported archives must fit in
 *   plain-zip metadata (entries < 65 535, sizes < 4 GiB). GitHub
 *   zipballs and Composer-shaped donor archives are comfortably under
 *   both limits, so this is not a real-world restriction.
 *
 * Returns names as **raw bytes** exactly as encoded in the archive;
 * the caller is responsible for the zip-slip lexical check.
 *
 * Format reference: PKWARE APPNOTE.TXT §4.3.16 (EOCD) and §4.3.12
 * (Central Directory File Header).
 *
 * @psalm-suppress MissingImmutableAnnotation reads the archive file
 */
final class ZipCentralDirectoryReader
{
    private const EOCD_SIGNATURE = "\x50\x4b\x05\x06";
    private const CD_FILE_HEADER_SIGNATURE = 0x02014b50;
    private const ZIP64_UINT32_SENTINEL = 0xFFFFFFFF;
    private const ZIP64_UINT16_SENTINEL = 0xFFFF;
    private const EOCD_MIN_SIZE = 22;
    private const CD_HEADER_FIXED_SIZE = 46;

    /**
     * @param non-empty-string $zipPath
     *
     * @return list<string> entry names in central-directory order
     *
     * @throws UnpackerException on missing / truncated / zip64 archives
     */
    public function readNames(string $zipPath): array
    {
        $size = @\filesize($zipPath);
        if ($size === false || $size < self::EOCD_MIN_SIZE) {
            throw new UnpackerException('archive is too small to be a valid zip');
        }

        // The EOCD's variable-length comment trails the fixed 22-byte
        // structure; the comment can be up to 65 535 bytes, so the
        // worst-case offset is `$size - 65 557`. Read that tail in one
        // go and locate the signature with strrpos — robust against
        // archives whose CD happens to contain the EOCD byte pattern.
        $maxTail = self::EOCD_MIN_SIZE + 0xFFFF;
        $tailLen = \min($size, $maxTail);

        $fh = @\fopen($zipPath, 'rb');
        if ($fh === false) {
            throw new UnpackerException('cannot open archive for reading: ' . $zipPath);
        }

        try {
            \fseek($fh, $size - $tailLen);
            $tail = \fread($fh, $tailLen);
            if ($tail === false || \strlen($tail) !== $tailLen) {
                throw new UnpackerException('failed to read archive tail');
            }

            // EOCD locator: scan back through every PK\x05\x06 match in
            // the tail, picking the FIRST one whose own `comment_len`
            // field correctly accounts for the bytes that follow it.
            // A naive `strrpos` would lock onto a signature that lives
            // inside the archive comment (which trails the real EOCD)
            // and parse adjacent comment bytes as EOCD fields. The
            // self-consistency check (`offset + 22 + comment_len` ==
            // tail length) rules that out: a fake match inside the
            // comment can't simultaneously satisfy the math.
            $unpacked = $this->findEocd($tail);
            if ($unpacked === null) {
                throw new UnpackerException(
                    'end-of-central-directory record not found — archive is corrupted or not a zip',
                );
            }

            $entries = $unpacked['entries'];
            $cdSize = $unpacked['cd_size'];
            $cdOffset = $unpacked['cd_offset'];

            if (
                $entries === self::ZIP64_UINT16_SENTINEL
                || $cdSize === self::ZIP64_UINT32_SENTINEL
                || $cdOffset === self::ZIP64_UINT32_SENTINEL
            ) {
                throw new UnpackerException(
                    'archive uses zip64 extensions — not supported by the CLI fallback. '
                    . 'Install ext-zip to handle archives larger than 4 GiB or containing '
                    . 'more than 65 535 entries.',
                );
            }

            if ($entries < 0 || $cdSize < 0 || $cdOffset < 0 || $cdOffset + $cdSize > $size) {
                throw new UnpackerException('central-directory offsets are out of range');
            }

            \fseek($fh, $cdOffset);
            $cd = \fread($fh, $cdSize);
            if ($cd === false || \strlen($cd) !== $cdSize) {
                throw new UnpackerException('failed to read central directory');
            }

            return self::parseCentralDirectory($cd, $entries);
        } finally {
            @\fclose($fh);
        }
    }

    /**
     * @return list<string>
     *
     * @psalm-pure
     */
    private static function parseCentralDirectory(string $cd, int $entries): array
    {
        $names = [];
        $pos = 0;
        $len = \strlen($cd);

        for ($i = 0; $i < $entries; $i++) {
            if ($pos + self::CD_HEADER_FIXED_SIZE > $len) {
                throw new UnpackerException('central directory truncated mid-header');
            }

            /** @var array{sig: int}|false $sig */
            $sig = @\unpack('Vsig', \substr($cd, $pos, 4));
            if ($sig === false || $sig['sig'] !== self::CD_FILE_HEADER_SIGNATURE) {
                throw new UnpackerException(\sprintf(
                    'unexpected signature at central-directory offset %d — archive is malformed',
                    $pos,
                ));
            }

            /** @var array{name_len: int, extra_len: int, comment_len: int}|false $lengths */
            $lengths = @\unpack(
                'vname_len/vextra_len/vcomment_len',
                \substr($cd, $pos + 28, 6),
            );
            if ($lengths === false) {
                throw new UnpackerException('failed to parse central-directory header lengths');
            }

            $nameLen = $lengths['name_len'];
            $extraLen = $lengths['extra_len'];
            $commentLen = $lengths['comment_len'];

            $nameStart = $pos + self::CD_HEADER_FIXED_SIZE;
            if ($nameStart + $nameLen > $len) {
                throw new UnpackerException('central directory truncated mid-name');
            }

            $names[] = \substr($cd, $nameStart, $nameLen);
            $pos = $nameStart + $nameLen + $extraLen + $commentLen;
        }

        return $names;
    }

    /**
     * Locate the End-of-Central-Directory record inside a tail buffer.
     *
     * The naive locator (`strrpos`) finds the LAST occurrence of the
     * signature, which is wrong when the archive comment itself
     * contains `PK\x05\x06` bytes — the fake match sits AFTER the real
     * EOCD and `strrpos` picks it. To stay robust we walk every
     * candidate from rightmost to leftmost and accept the first one
     * whose `comment_len` field is consistent with the file layout
     * (i.e. exactly `$tailLen - candidate - 22` bytes follow the EOCD
     * inside our tail buffer). A signature embedded in the comment
     * cannot satisfy that arithmetic.
     *
     * @return array{entries: int, cd_size: int, cd_offset: int}|null
     *
     * @psalm-pure
     */
    private function findEocd(string $tail): ?array
    {
        $tailLen = \strlen($tail);
        $cursor = $tailLen;
        while (true) {
            $candidate = \strrpos(\substr($tail, 0, $cursor), self::EOCD_SIGNATURE);
            if ($candidate === false) {
                return null;
            }

            $eocd = \substr($tail, $candidate, self::EOCD_MIN_SIZE);
            if (\strlen($eocd) === self::EOCD_MIN_SIZE) {
                /** @var array{entries: int, cd_size: int, cd_offset: int, comment_len: int}|false $unpacked */
                $unpacked = @\unpack(
                    'Vsig/vdisk/vcd_disk/ventries_disk/ventries/Vcd_size/Vcd_offset/vcomment_len',
                    $eocd,
                );
                if (
                    $unpacked !== false
                    && $candidate + self::EOCD_MIN_SIZE + $unpacked['comment_len'] === $tailLen
                ) {
                    return [
                        'entries' => $unpacked['entries'],
                        'cd_size' => $unpacked['cd_size'],
                        'cd_offset' => $unpacked['cd_offset'],
                    ];
                }
            }

            // Inconsistent candidate — restrict the next search to the
            // bytes strictly before this match and try again.
            $cursor = $candidate;
        }
    }
}
