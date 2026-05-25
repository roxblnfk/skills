<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo\Composer;

use Testo\Pipeline\Attribute\FallbackInterceptor;
use Testo\Pipeline\Attribute\Interceptable;

/**
 * Writes a `skills.json` file into the sandbox project's root for the
 * duration of the test, then removes it (and restores the original, if
 * any) when the test finishes.
 *
 * Used by acceptance tests covering the `skills.json` precedence /
 * fallback contract: the file's presence is the signal the mapper
 * looks at, so attaching this attribute toggles which source of
 * project config the next `composer skills:update` will read.
 *
 * Usage:
 *
 *     #[Test]
 *     #[WithSkillsJson(['target' => 'external-target/skills'])]
 *     public function externalConfigDrivesTarget(): void { ... }
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
#[FallbackInterceptor(WithSkillsJsonInterceptor::class)]
final readonly class WithSkillsJson implements Interceptable
{
    /**
     * @param array<string, mixed> $content value to encode at
     *        `<sandbox-root>/skills.json` for the duration of the test
     */
    public function __construct(
        public array $content,
    ) {}
}
