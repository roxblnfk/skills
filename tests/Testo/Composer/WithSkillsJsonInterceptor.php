<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo\Composer;

use LLM\Skills\Tests\Acceptance\Info;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * Executes the {@see WithSkillsJson} attribute: drop a `skills.json` at
 * the sandbox root for the duration of the test, restore the prior
 * state in `finally` so a failing assertion does not contaminate the
 * next test.
 *
 * Restoration handles three prior states:
 *
 * - **No file** — created by us; we delete it on teardown.
 * - **File existed already** — overwritten by us; we restore its
 *   original contents.
 *
 * In both cases the sandbox returns to the state it had before the
 * test ran.
 */
final readonly class WithSkillsJsonInterceptor implements TestRunInterceptor
{
    public function __construct(
        private WithSkillsJson $options,
    ) {}

    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        $path = Info::PROJECT_DIR . '/skills.json';

        $previousContent = \is_file($path) ? \file_get_contents($path) : null;

        \file_put_contents(
            $path,
            \json_encode(
                $this->options->content,
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            ) . "\n",
        );

        try {
            return $next($info);
        } finally {
            if ($previousContent === null || $previousContent === false) {
                @\unlink($path);
            } else {
                \file_put_contents($path, $previousContent);
            }
        }
    }
}
