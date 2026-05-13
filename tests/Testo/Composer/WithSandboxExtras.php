<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo\Composer;

use Testo\Pipeline\Attribute\FallbackInterceptor;
use Testo\Pipeline\Attribute\Interceptable;

/**
 * Replaces the sandbox project's `extra.skills` block with a per-test
 * snapshot, then restores the original file when the test finishes.
 *
 * Used by acceptance tests that need to exercise project-level
 * configuration paths the default sandbox cannot express, e.g.
 * `extra.skills.target` or `trusted-replace: true`. Because the sandbox is
 * shared across the suite and Composer's autoload state survives between
 * tests, we mutate only `composer.json` (never `composer.lock` or
 * `vendor/`), so subsequent tests find the project in its baseline state.
 *
 * Usage:
 *
 *     #[Test]
 *     #[WithSandboxExtras(['target' => 'custom-target/skills'])]
 *     public function customTargetIsHonoured(): void { ... }
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
#[FallbackInterceptor(WithSandboxExtrasInterceptor::class)]
final readonly class WithSandboxExtras implements Interceptable
{
    /**
     * @param array<string, mixed> $skills the value to install at
     *        `extra.skills` for the duration of the test
     */
    public function __construct(
        public array $skills,
    ) {}
}
