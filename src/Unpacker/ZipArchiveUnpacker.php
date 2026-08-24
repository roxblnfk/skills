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
 * Exclusions are honoured exactly: `\ZipArchive::extractTo()` takes a
 * literal name list, so every name the caller excludes is guaranteed to
 * stay out of the target tree — unlike the pattern-based CLI switches
 * in {@see CliUnpacker}.
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
     * @psalm-suppress UndefinedClass,MixedAssignment,MixedMethodCall,MixedPropertyFetch,MixedArgument,MixedArgumentTypeCoercion
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
            $entries = [];
            $count = $zip->numFiles;
            for ($i = 0; $i < $count; $i++) {
                /** @var string|false $name */
                $name = $zip->getNameIndex($i);
                if (!\is_string($name)) {
                    throw new UnpackerException(\sprintf('entry %d has an unreadable name', $i));
                }

                // `$opsys` is the same APPNOTE §4.4.2.2 host byte the
                // raw Central Directory reader derives from
                // `version_made_by`, so both paths share one symlink
                // definition.
                $opsys = 0;
                $attr = 0;
                $entries[] = new ZipEntry(
                    name: $name,
                    isSymlink: $zip->getExternalAttributesIndex($i, $opsys, $attr)
                        && ZipEntry::isSymlinkAttributes($opsys, $attr),
                );
            }
            return $entries;
        } finally {
            $zip->close();
        }
    }

    /**
     * @psalm-suppress UndefinedClass,MixedAssignment,MixedMethodCall,MixedArgument
     *         ext-zip is a soft requirement — guarded by class_exists above
     */
    #[\Override]
    public function extractTo(string $zipPath, string $targetDir, array $excludeNames = []): void
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
            $keep = $excludeNames === [] ? null : self::keptNames($zip, $excludeNames);

            // Everything excluded leaves no work; calling extractTo()
            // with an empty list is not portable.
            if ($keep === []) {
                return;
            }

            // The single-argument call is the far more exercised
            // ext-zip code path and avoids any name round-trip through
            // getNameIndex(); take it whenever nothing is excluded.
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
     * Entry names to pass to `\ZipArchive::extractTo()` — everything
     * except `$excludeNames`. Reading the names back from the same
     * `\ZipArchive` instance keeps them byte-identical to what
     * `extractTo()` matches against.
     *
     * @param non-empty-list<string> $excludeNames
     *
     * @return list<string>
     *
     * @psalm-suppress UndefinedClass,MixedAssignment,MixedMethodCall,MixedPropertyFetch,MixedArgument,MixedArgumentTypeCoercion
     *         ext-zip is a soft requirement — guarded by the caller's class_exists
     */
    private static function keptNames(\ZipArchive $zip, array $excludeNames): array
    {
        $excluded = [];
        foreach ($excludeNames as $excludeName) {
            $excluded["\0" . $excludeName] = true;
        }
        $keep = [];
        $count = $zip->numFiles;

        for ($i = 0; $i < $count; $i++) {
            /** @var string|false $name */
            $name = $zip->getNameIndex($i);
            if (!\is_string($name)) {
                throw new UnpackerException(\sprintf('entry %d has an unreadable name', $i));
            }
            if (!isset($excluded["\0" . $name])) {
                $keep[] = $name;
            }
        }

        return $keep;
    }
}
