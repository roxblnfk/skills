<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo\Composer;

use Testo\Pipeline\Attribute\FallbackInterceptor;
use Testo\Pipeline\Attribute\Interceptable;

/**
 * Marks an acceptance test (or every test in a class) as requiring a fresh
 * `composer skills:update` run inside the sandbox project before the test body
 * executes.
 *
 * The attribute is linked to {@see RunSyncCommandInterceptor} via
 * {@see FallbackInterceptor}; testo discovers and wires it automatically
 * through {@see \Testo\Pipeline\Internal\AttributesInterceptor}.
 *
 * Usage:
 * ```
 * #[Test]
 * #[RunSyncCommand]                      // wipes .claude/skills, then syncs
 * public function copiesSkillsFromBasicPackage(): void { ... }
 *
 * #[Test]
 * #[RunSyncCommand(cleanBefore: false)]  // incremental sync on top of existing files
 * public function reSyncOverExistingFiles(): void { ... }
 * ```
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
#[FallbackInterceptor(RunSyncCommandInterceptor::class)]
final readonly class RunSyncCommand implements Interceptable
{
    /**
     * @param bool $cleanBefore When `true`, the sync target directory
     *        (`<project>/.claude/skills`) is wiped before the sync command runs.
     *        Defaults to `true` so assertions on synced files only reflect this
     *        run, not leftovers from prior tests.
     */
    public function __construct(
        public bool $cleanBefore = true,
    ) {}
}
