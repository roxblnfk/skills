<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Fixture;

/**
 * Builders for `.zip` fixture archives, shared by every test that needs
 * one. All builders require `ext-zip` — callers self-skip when
 * `\ZipArchive` is unavailable.
 */
trait ZipFixtures
{
    /**
     * Fixture archive with default (MS-DOS-host) entries — no Unix
     * modes in `external_attr`.
     *
     * @param non-empty-string $dir existing directory to place the archive in
     * @param array<string, string> $files entry name → file contents
     *
     * @return non-empty-string absolute path of the built archive
     */
    private static function buildZipIn(string $dir, array $files): string
    {
        $zipPath = $dir . '/' . \bin2hex(\random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $zipPath;
    }

    /**
     * Fixture archive whose entries carry Unix modes: `$symlinks` names
     * get {@see ZipMode::SYMLINK} in the high half of
     * `external_attr`, everything else {@see ZipMode::REGULAR}.
     * For a symlink entry the content is the link target, exactly as a
     * real archive stores it.
     *
     * @param non-empty-string $dir existing directory to place the archive in
     * @param array<string, string> $files entry name → file contents
     * @param list<string> $symlinks names from `$files` to flag as links
     *
     * @return non-empty-string absolute path of the built archive
     */
    private static function buildUnixZipIn(string $dir, array $files, array $symlinks = []): string
    {
        $zipPath = $dir . '/' . \bin2hex(\random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
            $zip->setExternalAttributesName(
                $name,
                \ZipArchive::OPSYS_UNIX,
                (\in_array($name, $symlinks, true) ? ZipMode::SYMLINK : ZipMode::REGULAR) << 16,
            );
        }
        $zip->close();
        return $zipPath;
    }
}
