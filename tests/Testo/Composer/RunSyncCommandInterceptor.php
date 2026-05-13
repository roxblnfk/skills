<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo\Composer;

use Internal\Path;
use LLM\Skills\Tests\Acceptance\Info;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * Runs `composer skills:sync` inside {@see Info::PROJECT_DIR} before delegating
 * to the next interceptor. Triggered by the {@see RunSyncCommand} attribute on
 * a test method or class.
 *
 * The attribute instance is injected via DI by testo's
 * {@see \Testo\Pipeline\Internal\AttributesInterceptor}; the constructor
 * signature matches its convention even when the attribute carries no
 * configuration, so options can be added later without changing wiring.
 */
final readonly class RunSyncCommandInterceptor implements TestRunInterceptor
{
    public function __construct(
        private RunSyncCommand $options,
    ) {}

    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        if ($this->options->cleanBefore) {
            Filesystem::removeRecursive(Info::PROJECT_DIR . '/.agents/skills');
        }

        ComposerRunner::run(Path::create(Info::PROJECT_DIR), 'skills:sync');

        return $next($info);
    }
}
