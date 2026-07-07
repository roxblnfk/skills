<?php

declare(strict_types=1);

namespace LLM\Skills\Filesystem;

/**
 * Writes a file by staging the content in a sibling temp file and
 * renaming it into place. The rename is atomic on most filesystems
 * (POSIX + NTFS), so a concurrent reader never observes a
 * half-written file.
 *
 * Shared by every component that rewrites a project file in one shot
 * ({@see \LLM\Skills\Add\SkillsJsonWriter} on `skills:add`,
 * {@see \LLM\Skills\Config\Mapper\ProjectConfigMigrator} on the
 * in-place key rename) so the Windows-overwrite quirk lives in one
 * place.
 */
final class AtomicFileWriter
{
    /**
     * Stage `$content` in a sibling temp file, then rename it onto
     * `$filePath`.
     *
     * Windows quirk: {@see \rename()} refuses to overwrite an existing
     * destination on Windows (returns false / triggers a warning),
     * even though NTFS itself supports atomic replace via
     * `MoveFileEx(MOVEFILE_REPLACE_EXISTING)`. PHP's `rename` does not
     * pass that flag. So when the destination already exists we unlink
     * it first and retry; this opens a sub-millisecond window where
     * the file is gone, but the alternative — failing every second
     * write — is worse. POSIX never enters the retry path because the
     * initial rename overwrites cleanly.
     *
     * @throws \RuntimeException when the temp file cannot be written or renamed into place
     */
    public static function write(string $filePath, string $content): void
    {
        $tmp = $filePath . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        if (\file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException('failed to write temp file at ' . $tmp);
        }
        if (@\rename($tmp, $filePath)) {
            return;
        }
        // First rename failed. If the destination already exists, this
        // is the Windows-overwrite case; unlink and retry once.
        if (\file_exists($filePath) && @\unlink($filePath) && @\rename($tmp, $filePath)) {
            return;
        }
        @\unlink($tmp);
        throw new \RuntimeException('failed to rename temp file into ' . $filePath);
    }
}
