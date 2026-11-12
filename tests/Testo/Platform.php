<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo;

use Testo\Core\Exception\SkipTest;

/**
 * Platform guards for tests that only make sense on one OS family.
 */
final class Platform
{
    /**
     * Skip the running test on Windows, where aliases are NTFS junctions:
     * they always hold an absolute target, so relative-symlink behaviour
     * does not apply.
     */
    public static function requirePosixSymlinks(): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            throw new SkipTest('Windows junctions cannot hold a relative target');
        }
    }
}
