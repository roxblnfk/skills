<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints findings and turns them into an exit code.
 *
 * Text output is one finding per line, `LEVEL <file>[:<line>]: <message>`, then an
 * `N error(s), M warning(s)` summary; JSON output is an array of
 * `{level, file, line, message, code}`. Both are written raw, so a path or a message
 * quoting skill content is never read as console markup.
 *
 * @internal
 */
final class FindingReport
{
    /** Exit code when no finding is an error. */
    public const EXIT_OK = 0;

    /** Exit code when at least one finding is an error. */
    public const EXIT_ERRORS = 1;

    /**
     * Renders control characters (C0 except tab, DEL, C1) as `\xNN`, so skill content cannot
     * inject terminal escape sequences or break the one-finding-per-line format. In text that
     * is not valid UTF-8 every byte above ASCII is rendered so, since a lone C1 byte such as
     * 0x9B starts a sequence on 8-bit terminals.
     *
     * @psalm-pure
     */
    public static function escape(string $s): string
    {
        return (string) \preg_replace_callback(
            \preg_match('//u', $s) === 1
                ? '/[\x00-\x08\x0A-\x1F\x7F]|\xC2[\x80-\x9F]/'
                : '/[\x00-\x08\x0A-\x1F\x7F-\xFF]/',
            static fn(array $m): string => \sprintf('\\x%02X', \strlen($m[0]) === 2 ? \ord($m[0][1]) : \ord($m[0])),
            $s,
        );
    }

    /**
     * Writes the findings and returns the exit code they call for.
     *
     * @return self::EXIT_*
     */
    public static function write(FindingCollector $findings, bool $json, OutputInterface $output): int
    {
        $output->write(
            $json ? self::renderJson($findings) : self::renderText($findings),
            false,
            OutputInterface::OUTPUT_RAW,
        );

        return $findings->count(Severity::Error) > 0 ? self::EXIT_ERRORS : self::EXIT_OK;
    }

    /**
     * @psalm-mutation-free
     */
    private static function renderText(FindingCollector $findings): string
    {
        $out = '';
        foreach ($findings->findings() as $f) {
            $where = $f->file . ($f->line !== null ? ':' . $f->line : '');
            $out .= \str_pad($f->severity->value, 5) . ' ' . self::escape($where) . ': ' . self::escape($f->message) . "\n";
        }

        return $out . \sprintf(
            "%d error(s), %d warning(s)\n",
            $findings->count(Severity::Error),
            $findings->count(Severity::Warning),
        );
    }

    private static function renderJson(FindingCollector $findings): string
    {
        return \json_encode(
            \array_map(static fn(Finding $f): array => $f->toArray(), $findings->findings()),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
        ) . "\n";
    }
}
