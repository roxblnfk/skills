<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo\Composer;

use LLM\Skills\Tests\Acceptance\Info;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * Executes the {@see WithSandboxExtras} attribute: swap the sandbox
 * project's `extra.skills` block for the duration of the test, restore the
 * original `composer.json` afterward.
 *
 * Restoration runs in `finally`, so a failing assertion does not leave the
 * sandbox in a mutated state that contaminates the next test.
 */
final readonly class WithSandboxExtrasInterceptor implements TestRunInterceptor
{
    public function __construct(
        private WithSandboxExtras $options,
    ) {}

    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        $path = Info::PROJECT_DIR . '/composer.json';

        $original = \file_get_contents($path);
        if ($original === false) {
            throw new \RuntimeException(\sprintf('Unable to read sandbox composer.json at %s', $path));
        }

        /** @var array<string, mixed> $data */
        $data = \json_decode($original, true, flags: \JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $extra */
        $extra = \is_array($data['extra'] ?? null) ? $data['extra'] : [];
        $extra['skills'] = $this->options->skills;
        $data['extra'] = $extra;

        \file_put_contents(
            $path,
            \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n",
        );

        try {
            return $next($info);
        } finally {
            \file_put_contents($path, $original);
        }
    }
}
