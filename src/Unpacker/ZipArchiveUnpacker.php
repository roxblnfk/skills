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
            \assert(\is_int($count));
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

        $extracted = $zip->extractTo($targetDir);
        $zip->close();
        if ($extracted === false) {
            throw new UnpackerException('failed to extract archive into ' . $targetDir);
        }
    }
}
