<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo;

/**
 * Snapshot/restore helper for the shared sandbox `composer.json` and
 * `skills.json` files.
 *
 * Auto-migration in `skills:update` (and the `post-update-cmd`
 * auto-sync hook) rewrites `composer.json` and creates
 * `skills.json`. Tests share the sandbox directory, so without an
 * explicit restore one test's migration would leak into the next
 * (e.g. the second `skills:update` run would see the migrated
 * skills.json from the first run instead of the inline block the
 * sandbox ships with).
 *
 * Usage:
 *
 *     #[BeforeTest]
 *     public function snapshotSandbox(): void
 *     {
 *         SandboxStateGuard::snapshot();
 *     }
 *
 *     #[AfterTest]
 *     public function restoreSandbox(): void
 *     {
 *         SandboxStateGuard::restore();
 *     }
 *
 * Idempotent on missing files: `composer.json` is always present in
 * the sandbox (snapshot is mandatory); `skills.json` may not be
 * (snapshot records its absence, restore deletes the file if it
 * appeared during the test).
 */
final class SandboxStateGuard
{
    private static ?string $composerJsonSnapshot = null;

    /** False when no skills.json existed at snapshot time. */
    private static string|false $skillsJsonSnapshot = false;

    public static function snapshot(): void
    {
        $composerPath = self::composerJsonPath();
        $content = \file_get_contents($composerPath);
        if ($content === false) {
            throw new \RuntimeException(\sprintf(
                'SandboxStateGuard: failed to read %s for snapshot',
                $composerPath,
            ));
        }
        self::$composerJsonSnapshot = $content;

        $skillsPath = self::skillsJsonPath();
        if (\is_file($skillsPath)) {
            $skills = \file_get_contents($skillsPath);
            if ($skills === false) {
                throw new \RuntimeException(\sprintf(
                    'SandboxStateGuard: skills.json present at %s but unreadable',
                    $skillsPath,
                ));
            }
            self::$skillsJsonSnapshot = $skills;
        } else {
            self::$skillsJsonSnapshot = false;
        }
    }

    public static function restore(): void
    {
        if (self::$composerJsonSnapshot !== null) {
            \file_put_contents(self::composerJsonPath(), self::$composerJsonSnapshot);
        }

        $skillsPath = self::skillsJsonPath();
        if (self::$skillsJsonSnapshot === false) {
            if (\is_file($skillsPath)) {
                @\unlink($skillsPath);
            }
        } else {
            \file_put_contents($skillsPath, self::$skillsJsonSnapshot);
        }
    }

    private static function composerJsonPath(): string
    {
        return \LLM\Skills\Tests\Acceptance\Info::PROJECT_DIR . '/composer.json';
    }

    private static function skillsJsonPath(): string
    {
        return \LLM\Skills\Tests\Acceptance\Info::PROJECT_DIR . '/skills.json';
    }
}
