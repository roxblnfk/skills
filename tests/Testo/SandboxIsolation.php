<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo;

use Internal\Container\Container;
use LLM\Skills\Tests\Acceptance\Info;
use Testo\Common\PluginConfigurator;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\InterceptorCollector;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * Restores the sandbox project's config files around every acceptance
 * test, whether or not the test declares an attribute that touches
 * them.
 *
 * The whole acceptance suite drives one shared consumer project at
 * {@see Info::PROJECT_DIR}, and the commands under test rewrite its
 * config on purpose: `skills:update` migrates legacy inline
 * `extra.skills` out of `composer.json` and into a fresh
 * `skills.json`, `skills:init` and `skills:add` write `skills.json`
 * from scratch. A file left behind by one test silently reconfigures
 * every test after it — `skills.json` takes precedence over inline
 * `extra.skills`, so the next test's `#[WithSandboxExtras]` block is
 * read but never consulted, and the failure surfaces somewhere else
 * entirely as "the trust list / target / alias I configured did not
 * apply".
 *
 * Per-test attributes ({@see Composer\WithSkillsJson},
 * {@see Composer\WithSandboxExtras}) restore what they themselves
 * wrote, which is not the same guarantee: neither knows about a file
 * the *command under test* created, and a pre-existing file is
 * faithfully put back rather than removed. This interceptor closes
 * that gap for every test uniformly, so no acceptance test can depend
 * on — or corrupt — the state another one left.
 *
 * Ordering is the point: `order` sits outside
 * {@see InterceptorOptions::ORDER_ATTRIBUTES}, so the snapshot is
 * taken before any attribute interceptor writes its fixture and the
 * restore runs after the last one has unwound. Placed any deeper, it
 * would snapshot a fixture as if it were the baseline and re-create it
 * for the following test.
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_ATTRIBUTES - 1)]
final class SandboxIsolation implements PluginConfigurator, TestRunInterceptor
{
    #[\Override]
    public function configure(Container $container): void
    {
        $container->get(InterceptorCollector::class)->addInterceptor($this);
    }

    /**
     * The two sandbox files whose lifetime this class owns.
     *
     * @return list<non-empty-string>
     */
    public static function configFiles(): array
    {
        return [Info::PROJECT_DIR . '/composer.json', Info::PROJECT_DIR . '/skills.json'];
    }

    /**
     * Snapshot the config files' current contents (`null` = absent),
     * keyed by path, for a later {@see self::restore()}.
     *
     * @return array<non-empty-string, string|null>
     */
    public static function snapshot(): array
    {
        $snapshot = [];
        foreach (self::configFiles() as $path) {
            $snapshot[$path] = self::read($path);
        }

        return $snapshot;
    }

    /**
     * Put each snapshotted file back to its recorded contents, deleting
     * the ones the snapshot recorded as absent.
     *
     * @param array<non-empty-string, string|null> $snapshot
     */
    public static function restore(array $snapshot): void
    {
        foreach ($snapshot as $path => $content) {
            self::write($path, $content);
        }
    }

    #[\Override]
    public function runTest(TestInfo $info, callable $next): TestResult
    {
        $snapshot = self::snapshot();

        try {
            return $next($info);
        } finally {
            self::restore($snapshot);
        }
    }

    /**
     * Contents of `$path`, or `null` when the file does not exist.
     *
     * An unreadable *existing* file is fatal rather than `null`: the
     * `null` would be read as "absent" on restore and delete the
     * original.
     */
    private static function read(string $path): ?string
    {
        // These files are written by the `composer` subprocess under
        // test, and PHP only invalidates its stat cache for paths it
        // touched itself — an `is_file()` here would answer from a
        // cache that predates the previous test's subprocess.
        \clearstatcache(true, $path);

        if (!\is_file($path)) {
            return null;
        }

        $content = \file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(\sprintf(
                'SandboxIsolation: %s exists but is unreadable; refusing to run a test that '
                . 'would lose it on teardown.',
                $path,
            ));
        }

        return $content;
    }

    /**
     * Put `$path` back to `$content`, deleting it when the snapshot
     * recorded no file.
     */
    private static function write(string $path, ?string $content): void
    {
        if ($content === null) {
            // Unconditionally, without an `is_file()` guard: the file
            // was created by a subprocess, so the guard would consult
            // a stat cache that never saw it appear and skip the
            // delete — which is the leak this class exists to stop.
            @\unlink($path);
            return;
        }

        \file_put_contents($path, $content);
    }
}
