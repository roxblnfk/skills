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

        // Distinguish three states for the post-test restore:
        //   - file did not exist  → $previousContent = null  (delete in finally)
        //   - file existed, read OK → $previousContent = string (write back)
        //   - file existed, read failed → throw NOW, because finally would
        //     otherwise see `false` and delete the original — corrupting the
        //     sandbox on an unrelated IO failure.
        $previousContent = null;
        if (\is_file($path)) {
            $read = \file_get_contents($path);
            if ($read === false) {
                throw new \RuntimeException(\sprintf(
                    'WithSkillsJsonInterceptor: unable to read existing %s; refusing to '
                    . 'overwrite to avoid losing the original on teardown.',
                    $path,
                ));
            }
            $previousContent = $read;
        }

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
            if ($previousContent === null) {
                @\unlink($path);
            } else {
                \file_put_contents($path, $previousContent);
            }
        }
    }
}
