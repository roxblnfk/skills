<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo;

use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;

/**
 * Starts the running PHP binary as a server without the mbstring and intl
 * extensions would run it: `-n` skips php.ini and every extension it loads.
 * openssl is loaded back when `-n` drops it, since Composer's HTTP client
 * refuses to start without it.
 */
final class PhpWithoutMbstring
{
    /** @var list<string>|false|null `false` until probed */
    private static array|false|null $command = false;

    /**
     * The command prefix, or a skip when this PHP cannot run without the
     * extensions. A skip in CI would hide that the polyfill path went untested,
     * so with the `CI` environment variable set the test fails instead.
     *
     * @return list<string>
     */
    public static function commandOrSkip(): array
    {
        $command = self::command();
        if ($command !== null) {
            return $command;
        }

        $reason = 'mbstring or intl is compiled into PHP, or openssl cannot be loaded without php.ini';
        if (\in_array(\getenv('CI'), [false, '', '0', 'false'], true)) {
            throw new SkipTest($reason);
        }

        Assert::fail($reason . '; CI must run this test on a PHP that loads them as shared extensions');
    }

    /**
     * @return list<string>|null the command prefix, or `null` when mbstring or intl is
     *         compiled into this PHP and cannot be left out, or openssl cannot be loaded back
     */
    private static function command(): ?array
    {
        if (self::$command !== false) {
            return self::$command;
        }

        $variants = [
            [],
            ['-d', 'extension_dir=' . (string) \ini_get('extension_dir'), '-d', 'extension=openssl'],
        ];
        foreach ($variants as $options) {
            // Startup warnings go to stderr so they cannot corrupt the probe's JSON.
            $command = [\PHP_BINARY, '-n', '-d', 'display_errors=stderr', ...$options];
            $probe = new Process([
                ...$command,
                '-r',
                'echo json_encode([extension_loaded("mbstring") || extension_loaded("intl"), extension_loaded("openssl")]);',
            ]);
            $probe->run();

            /** @var mixed $loaded */
            $loaded = \json_decode($probe->getOutput(), true);
            if (!\is_array($loaded) || $loaded[0] !== false) {
                return self::$command = null;
            }

            if ($loaded[1] === true) {
                return self::$command = $command;
            }
        }

        return self::$command = null;
    }
}
