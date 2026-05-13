<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo\Composer;

use Internal\Path;
use LLM\Skills\Tests\Acceptance\Info;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Core\Context\SuiteInfo;
use Testo\Core\Context\SuiteResult;
use Testo\Pipeline\Middleware\TestSuiteRunInterceptor;

/**
 * Runs `composer install` inside {@see Info::PROJECT_DIR} before the acceptance suite executes.
 *
 * Idempotent: if the project's `vendor/autoload.php` already exists, the install is skipped.
 * The project uses path repositories with symlinks, so source changes in `src/` (plugin) and
 * `tests/Sandbox/packages/*` (stub packages) are picked up without re-installing.
 *
 * When `$cleanup` is true, the project's `vendor/` is removed in a `finally` block after the
 * suite completes — useful for CI (pristine state every run). Off by default so local reruns
 * stay fast.
 */
final readonly class ComposerInstallInterceptor implements TestSuiteRunInterceptor
{
    public function __construct(
        private Path $projectRoot,
        private bool $cleanup = false,
    ) {}

    #[\Override]
    public function runTestSuite(SuiteInfo $info, callable $next): SuiteResult
    {
        $autoload = $this->projectRoot->join('vendor', 'autoload.php');

        if (!$autoload->isFile()) {
            ComposerRunner::run($this->projectRoot, 'install --prefer-dist');
        }

        try {
            return $next($info);
        } finally {
            if ($this->cleanup) {
                $this->cleanupProject();
            }
        }
    }

    /**
     * Remove the sandbox project's `vendor/` directory plus any `.!!*` leftovers
     * from prior failed deletions.
     *
     * Recursion is junction-safe (see {@see Filesystem::removeRecursive()}). This
     * matters because path-repo dependencies (`llm/skills`, `acme/skills-*`) are
     * junctioned in from outside the sandbox — descending into them would attempt
     * to delete the plugin's own source tree.
     */
    private function cleanupProject(): void
    {
        \fwrite(\STDERR, "[acceptance] cleanup: removing tests/Sandbox/project/vendor …\n");

        $project = (string) $this->projectRoot;
        Filesystem::removeRecursive($project . '/vendor');

        // Sweep up leftovers from previous failed runs (Symfony Filesystem leaves
        // them when its rename-then-delete trick gives up partway through).
        foreach (\glob($project . '/.!!*') ?: [] as $stale) {
            Filesystem::removeRecursive($stale);
        }
    }
}
