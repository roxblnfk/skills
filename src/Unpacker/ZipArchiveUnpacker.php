<?php

declare(strict_types=1);

namespace LLM\Skills\Unpacker;

/**
 * `ext-zip`-backed unpacker — the primary path on machines that ship
 * `php_zip` (which is the case for the overwhelming majority of PHP
 * builds, including Composer's own runtime).
 *
 * Used in-process so no subprocess is spawned and no temp files beyond
 * the scratch directory are involved.
 *
 * Symlink entries are skipped, matching {@see CliUnpacker}. `ext-zip`
 * does not hit the Windows privilege wall the CLI tools do — it writes
 * the link target as ordinary file content — but that leaves a file
 * whose bytes are a path, which the copy step would then treat as real
 * content. Since {@see \LLM\Skills\Filesystem\LinkGuard} drops symlinks
 * on the way into the target anyway, dropping them at extraction keeps
 * both unpackers producing the same tree.
 *
 * @psalm-suppress MissingImmutableAnnotation
 *         stateless wrapper, but `listEntries`/`extractTo` perform I/O
 */
final readonly class ZipArchiveUnpacker implements ArchiveUnpacker
{
    /**
     * @psalm-pure
     */
    #[\Override]
    public function id(): string
    {
        return 'ziparchive';
    }

    /**
     * @psalm-suppress UndefinedClass,MixedAssignment,MixedMethodCall,MixedPropertyFetch,MixedArgument
     *         ext-zip is a soft requirement — guarded by class_exists above
     */
    #[\Override]
    public function listEntries(string $zipPath): array
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new UnpackerException(
                'ZipArchive is not available — this unpacker should not have been selected',
            );
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new UnpackerException(
                \sprintf('failed to open archive (zip error %s)', \var_export($openResult, true)),
            );
        }

        try {
            $names = [];
            $count = $zip->numFiles;
            for ($i = 0; $i < $count; $i++) {
                /** @var string|false $name */
                $name = $zip->getNameIndex($i);
                if (!\is_string($name)) {
                    throw new UnpackerException(\sprintf('entry %d has an unreadable name', $i));
                }
                $names[] = $name;
            }
            return $names;
        } finally {
            $zip->close();
        }
    }

    /**
     * @psalm-suppress UndefinedClass,MixedAssignment,MixedMethodCall,MixedArgument
     *         ext-zip is a soft requirement — guarded by class_exists above
     */
    #[\Override]
    public function extractTo(string $zipPath, string $targetDir): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new UnpackerException('ZipArchive is not available');
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new UnpackerException(
                \sprintf('failed to open archive (zip error %s)', \var_export($openResult, true)),
            );
        }

        try {
            $keep = self::nonSymlinkNames($zip);

            // An archive of nothing but symlinks leaves no work; calling
            // extractTo() with an empty list is not portable.
            if ($keep === []) {
                return;
            }

            // Pass the explicit name list only when something was
            // filtered out — the unfiltered call is the well-trodden
            // path and stays byte-identical to the previous behaviour
            // for the overwhelming majority of archives.
            $extracted = $keep === null
                ? $zip->extractTo($targetDir)
                : $zip->extractTo($targetDir, $keep);
            if ($extracted === false) {
                throw new UnpackerException('failed to extract archive into ' . $targetDir);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Names to extract, or `null` when the archive holds no symlink and
     * the caller should extract everything.
     *
     * The Unix mode lives in the high 16 bits of the entry's external
     * attributes and is only meaningful when the entry was written by a
     * Unix-family host — an MS-DOS-host entry keeps DOS attribute flags
     * there instead, so reading a mode out of it would classify
     * arbitrary files as links.
     *
     * @return list<string>|null
     *
     * @psalm-suppress UndefinedClass,MixedAssignment,MixedMethodCall,MixedPropertyFetch,MixedArgument,MixedArgumentTypeCoercion
     *         ext-zip is a soft requirement — guarded by the caller's class_exists
     */
    private static function nonSymlinkNames(\ZipArchive $zip): ?array
    {
        $keep = [];
        $sawSymlink = false;
        $count = $zip->numFiles;

        for ($i = 0; $i < $count; $i++) {
            /** @var string|false $name */
            $name = $zip->getNameIndex($i);
            if (!\is_string($name)) {
                throw new UnpackerException(\sprintf('entry %d has an unreadable name', $i));
            }

            $opsys = 0;
            $attr = 0;
            if (
                $zip->getExternalAttributesIndex($i, $opsys, $attr)
                && $opsys === \ZipArchive::OPSYS_UNIX
                && ((($attr >> 16) & 0xFFFF) & 0xF000) === 0xA000
            ) {
                $sawSymlink = true;
                continue;
            }

            $keep[] = $name;
        }

        return $sawSymlink ? $keep : null;
    }
}
