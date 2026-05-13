<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Testo\Assert;
use Testo\Test;

/**
 * Placeholder for acceptance tests.
 *
 * Acceptance tests run real Composer commands against {@see tests/Sandbox}
 * and assert observable behaviour (exit codes, files on disk, stdout).
 *
 * See {@see tests/Sandbox/composer.json} — the sandbox installs `llm/skills`
 * via a path repository pointing at the package root, so any change in `src/`
 * is picked up immediately by the symlink.
 */
final class SmokeTest
{
    #[Test]
    public function sandboxProjectComposerJsonExists(): void
    {
        Assert::true(
            \is_file(Info::PROJECT_DIR . '/composer.json'),
            'Sandbox project composer.json must exist',
        );
    }
}
