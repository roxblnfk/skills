<?php

/**
 * Shared code for the llm-skills helper scripts: findings report, CLI parsing,
 * path helpers and a YAML-subset parser for SKILL.md frontmatter. Dependency-free;
 * PHP 8.2+. Skill validation itself is the plugin's `skills:lint` command.
 */

declare(strict_types=1);

namespace LlmSkills\Scripts;

final class UsageError extends \RuntimeException
{
}

/**
 * Collects findings and prints them as text lines or as a JSON array.
 */
final class Report
{
    /** @var list<array{level: string, file: string, line: int|null, message: string, code: string}> */
    private array $items = [];

    /** @var array<string, true> */
    private array $seen = [];

    public function error(string $file, ?int $line, string $code, string $message): void
    {
        $this->add('ERROR', $file, $line, $code, $message);
    }

    public function warn(string $file, ?int $line, string $code, string $message): void
    {
        $this->add('WARN', $file, $line, $code, $message);
    }

    public function info(string $file, ?int $line, string $code, string $message): void
    {
        $this->add('INFO', $file, $line, $code, $message);
    }

    public function add(string $level, string $file, ?int $line, string $code, string $message): void
    {
        // The same file can be reached through several scan roots; report it once.
        $key = "$level|$file|$line|$code|$message";
        if (isset($this->seen[$key])) {
            return;
        }
        $this->seen[$key] = true;
        $this->items[] = ['level' => $level, 'file' => $file, 'line' => $line, 'message' => $message, 'code' => $code];
    }

    /**
     * Renders control characters (C0 except tab, DEL, C1) as \xNN so scanned content cannot
     * inject terminal escape sequences or break the one-finding-per-line format.
     */
    public static function escape(string $s): string
    {
        return (string) \preg_replace_callback(
            '/[\x00-\x08\x0A-\x1F\x7F]|\xC2[\x80-\x9F]/',
            static fn(array $m): string => \sprintf('\\x%02X', \strlen($m[0]) === 2 ? \ord($m[0][1]) : \ord($m[0])),
            $s,
        );
    }

    public function count(string $level): int
    {
        return \count(\array_filter($this->items, static fn(array $i): bool => $i['level'] === $level));
    }

    /**
     * Prints every finding and returns the exit code: 1 when any error was reported, else 0.
     *
     * @param list<string> $trailer extra lines printed after the summary in text mode, to stderr in JSON mode
     */
    public function emit(bool $json, array $trailer = []): int
    {
        if ($json) {
            echo \json_encode($this->items, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE), "\n";
            foreach ($trailer as $line) {
                \fwrite(\STDERR, self::escape($line) . "\n");
            }
        } else {
            foreach ($this->items as $i) {
                $where = $i['file'] . ($i['line'] !== null ? ':' . $i['line'] : '');
                echo \str_pad($i['level'], 5), ' ', self::escape($where), ': ', self::escape($i['message']), "\n";
            }
            \printf("%d error(s), %d warning(s)\n", $this->count('ERROR'), $this->count('WARN'));
            foreach ($trailer as $line) {
                echo self::escape($line), "\n";
            }
        }

        return $this->count('ERROR') > 0 ? 1 : 0;
    }
}

final class Cli
{
    /**
     * Splits argv into options and positional arguments.
     *
     * @param list<string> $argv arguments without the script name
     * @param list<string> $flags options without a value, e.g. "json"
     * @param list<string> $valued options taking "=value" or a following argument, e.g. "root"
     * @return array{0: array<string, string|true>, 1: list<string>}
     */
    public static function parse(array $argv, array $flags, array $valued): array
    {
        $opts = [];
        $positional = [];
        $onlyPositional = false;
        for ($i = 0, $n = \count($argv); $i < $n; $i++) {
            $arg = $argv[$i];
            if ($onlyPositional || $arg === '-' || !\str_starts_with($arg, '-')) {
                $positional[] = $arg;
                continue;
            }
            if ($arg === '--') {
                $onlyPositional = true;
                continue;
            }
            if ($arg === '-h') {
                $opts['help'] = true;
                continue;
            }
            if (!\str_starts_with($arg, '--')) {
                throw new UsageError("unknown option \"$arg\"");
            }
            [$name, $value] = \array_pad(\explode('=', \substr($arg, 2), 2), 2, null);
            if (\in_array($name, $flags, true)) {
                if ($value !== null) {
                    throw new UsageError("option --$name does not take a value");
                }
                $opts[$name] = true;
            } elseif (\in_array($name, $valued, true)) {
                if ($value === null) {
                    if (!isset($argv[$i + 1])) {
                        throw new UsageError("option --$name requires a value");
                    }
                    $value = $argv[++$i];
                }
                if ($value === '') {
                    throw new UsageError("option --$name requires a non-empty value");
                }
                $opts[$name] = $value;
            } else {
                throw new UsageError("unknown option \"--$name\"");
            }
        }

        return [$opts, $positional];
    }

    /**
     * Runs a CLI entry point, mapping a usage error to exit code 2.
     *
     * @param callable(): int $main
     */
    public static function run(callable $main, string $usage): int
    {
        try {
            return $main();
        } catch (UsageError $e) {
            \fwrite(\STDERR, 'error: ' . $e->getMessage() . "\n\n" . $usage);

            return 2;
        }
    }
}

final class Fs
{
    private static ?string $cwd = null;

    public static function cwd(): string
    {
        return self::$cwd ??= self::normalize((string) \getcwd());
    }

    public static function isAbsolute(string $path): bool
    {
        return \str_starts_with($path, '/') || \str_starts_with($path, '\\')
            || \preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    /**
     * Lexically normalised absolute path with forward slashes.
     */
    public static function abs(string $path, ?string $base = null): string
    {
        $path = \str_replace('\\', '/', $path);
        if (!self::isAbsolute($path)) {
            $path = ($base ?? self::cwd()) . '/' . $path;
        }

        return self::normalize($path);
    }

    public static function normalize(string $path): string
    {
        $path = \str_replace('\\', '/', $path);
        $prefix = '';
        if (\preg_match('~^([A-Za-z]:)/?~', $path, $m)) {
            $prefix = \strtoupper($m[1]) . '/';
            $path = \substr($path, \strlen($m[0]));
        } elseif (\str_starts_with($path, '//')) {
            $prefix = '//';
            $path = \substr($path, 2);
        } elseif (\str_starts_with($path, '/')) {
            $prefix = '/';
            $path = \substr($path, 1);
        }
        $out = [];
        foreach (\explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($out !== [] && \end($out) !== '..') {
                    \array_pop($out);
                } elseif ($prefix === '') {
                    $out[] = '..';
                }
                continue;
            }
            $out[] = $seg;
        }
        $joined = $prefix . \implode('/', $out);

        return $joined === '' ? '.' : $joined;
    }

    /**
     * Path relative to the working directory when inside it, else absolute; forward slashes.
     */
    public static function rel(string $path): string
    {
        $abs = self::abs($path);
        $cwd = self::cwd();
        $fold = \PHP_OS_FAMILY === 'Windows' ? 'strtolower' : static fn(string $s): string => $s;
        if ($fold($abs) === $fold($cwd)) {
            return '.';
        }
        $prefix = \rtrim($cwd, '/') . '/';
        if (\str_starts_with($fold($abs), $fold($prefix))) {
            return \substr($abs, \strlen($prefix));
        }

        return $abs;
    }

    /**
     * Whether the path itself is a symlink or (on Windows) a junction.
     */
    public static function isLink(string $path): bool
    {
        if (\is_link($path)) {
            return true;
        }
        if (!\file_exists($path)) {
            return false;
        }
        $real = \realpath($path);
        $parent = \realpath(\dirname($path));
        if ($real === false || $parent === false) {
            return false;
        }
        $expected = self::normalize($parent . '/' . \basename($path));
        $real = self::normalize($real);

        return \PHP_OS_FAMILY === 'Windows' ? \strcasecmp($expected, $real) !== 0 : $expected !== $real;
    }

    /**
     * Sorted directory entries without "." and "..".
     *
     * @return list<string>
     */
    public static function entries(string $dir): array
    {
        $list = @\scandir($dir);
        if ($list === false) {
            return [];
        }
        $list = \array_values(\array_diff($list, ['.', '..']));
        \sort($list, \SORT_STRING);

        return $list;
    }

    public static function readJson(string $file, ?string &$error): mixed
    {
        $error = null;
        $text = @\file_get_contents($file);
        if ($text === false) {
            $error = 'cannot be read';

            return null;
        }
        $text = \preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        try {
            return \json_decode($text, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $error = $e->getMessage();

            return null;
        }
    }

    /**
     * 1-based line of the first `"key":` occurrence in a JSON text, or null.
     */
    public static function jsonKeyLine(string $text, string $key): ?int
    {
        $pos = \preg_match('/"' . \preg_quote($key, '/') . '"\s*:/', $text, $m, \PREG_OFFSET_CAPTURE) === 1
            ? $m[0][1] : null;

        return $pos === null ? null : \substr_count($text, "\n", 0, $pos) + 1;
    }
}

final class Text
{
    /**
     * UTF-8 encoding of a code point.
     */
    public static function chr(int $cp): string
    {
        return match (true) {
            $cp < 0x80 => \chr($cp),
            $cp < 0x800 => \chr(0xC0 | $cp >> 6) . \chr(0x80 | $cp & 0x3F),
            $cp < 0x10000 => \chr(0xE0 | $cp >> 12) . \chr(0x80 | $cp >> 6 & 0x3F) . \chr(0x80 | $cp & 0x3F),
            default => \chr(0xF0 | $cp >> 18) . \chr(0x80 | $cp >> 12 & 0x3F) . \chr(0x80 | $cp >> 6 & 0x3F) . \chr(0x80 | $cp & 0x3F),
        };
    }

    public static function nfc(string $s): string
    {
        if (\class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);

            return \is_string($n) ? $n : $s;
        }

        return $s;
    }

    /**
     * The key llm/skills compares directory names by: NFC, then simple case folding.
     */
    public static function collisionKey(string $s): string
    {
        // Folding would turn invalid UTF-8 into "?" and make distinct names collide.
        if (\preg_match('//u', $s) !== 1) {
            return \strtolower($s);
        }
        $s = self::nfc($s);

        // The mbstring polyfill lacks this constant, and its full folding merges "ß" and "ss".
        return \defined('MB_CASE_FOLD_SIMPLE') ? \mb_convert_case($s, \MB_CASE_FOLD_SIMPLE, 'UTF-8') : \strtolower($s);
    }

    /**
     * Closest candidate within a small edit distance, or null.
     *
     * @param list<string> $candidates
     */
    public static function nearest(string $word, array $candidates): ?string
    {
        $best = null;
        $bestDistance = \PHP_INT_MAX;
        $w = \strtolower(\str_replace('_', '-', $word));
        foreach ($candidates as $candidate) {
            $d = \levenshtein($w, \str_replace('_', '-', $candidate));
            if ($d < $bestDistance) {
                $best = $candidate;
                $bestDistance = $d;
            }
        }
        $limit = \max(1, \min(3, intdiv(\strlen($word), 3)));

        return $best !== null && $bestDistance <= $limit && $best !== $word ? $best : null;
    }
}

final class YamlError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $yamlLine)
    {
        parent::__construct($message);
    }
}

/**
 * Parser for the YAML subset used in frontmatter: block mappings and sequences at any depth,
 * plain/single/double-quoted scalars (also multi-line), literal and folded block scalars,
 * and flow sequences/mappings. Anchors, aliases and tags are tolerated and reported as notes.
 *
 * Nodes: ['t' => 'map'|'list'|'str', 'v' => array|string, 'line' => int, 'style' => string].
 * Map values are keyed by the YAML key; every scalar stays a string, as strictyaml reads it.
 */
final class Yaml
{
    /** @var list<array{raw: string, no: int}> */
    private array $lines = [];

    private const MAX_DEPTH = 64;

    private int $pos = 0;

    private int $depth = 0;

    /** @var list<array{line: int, code: string, message: string}> */
    public array $notes = [];

    /**
     * @param int $firstLine file line number of the first frontmatter line
     */
    public function __construct(string $text, int $firstLine)
    {
        foreach (\preg_split('/\r\n|\n|\r/', $text) ?: [] as $i => $raw) {
            $this->lines[] = ['raw' => $raw, 'no' => $firstLine + $i];
        }
    }

    /**
     * @return array{t: string, v: mixed, line: int, style: string}
     */
    public function parseDocument(): array
    {
        $node = $this->parseNode(-1, false);
        $this->skipBlank();
        if ($this->pos < \count($this->lines)) {
            throw new YamlError('unexpected content "' . \trim($this->lines[$this->pos]['raw']) . '"', $this->lines[$this->pos]['no']);
        }

        return $node ?? ['t' => 'map', 'v' => [], 'line' => $this->lines[0]['no'] ?? 1, 'style' => 'block'];
    }

    /**
     * Converts a node to plain PHP values (strings, arrays, null for an empty value).
     */
    public static function plain(?array $node): mixed
    {
        if ($node === null) {
            return null;
        }

        return match ($node['t']) {
            'map' => \array_map(self::plain(...), $node['v']),
            'list' => \array_map(self::plain(...), $node['v']),
            default => $node['style'] === 'null' ? null : $node['v'],
        };
    }

    private function skipBlank(): void
    {
        while ($this->pos < \count($this->lines)) {
            $t = \trim($this->lines[$this->pos]['raw']);
            if ($t !== '' && !\str_starts_with($t, '#')) {
                return;
            }
            $this->pos++;
        }
    }

    /**
     * @return array{0: int, 1: string}|null indent and text of the next significant line
     */
    private function peek(): ?array
    {
        $this->skipBlank();
        if ($this->pos >= \count($this->lines)) {
            return null;
        }
        $raw = $this->lines[$this->pos]['raw'];
        $indent = \strspn($raw, ' ');
        if (isset($raw[$indent]) && $raw[$indent] === "\t") {
            throw new YamlError('tab character used for indentation; indent with spaces only', $this->lines[$this->pos]['no']);
        }

        return [$indent, \substr($raw, $indent)];
    }

    private function lineNo(): int
    {
        return $this->lines[\min($this->pos, \count($this->lines) - 1)]['no'] ?? 1;
    }

    private static function isListItem(string $text): bool
    {
        return $text === '-' || \str_starts_with($text, '- ') || \str_starts_with($text, "-\t");
    }

    private function parseNode(int $parentIndent, bool $allowSameIndentList): ?array
    {
        $p = $this->peek();
        if ($p === null) {
            return null;
        }
        [$indent, $text] = $p;
        if (self::isListItem($text) && ($indent > $parentIndent || ($allowSameIndentList && $indent === $parentIndent))) {
            return $this->parseList($indent);
        }
        if ($indent <= $parentIndent) {
            return null;
        }
        // A value may start on the next, deeper line: "key:\n  text" or "key:\n  \"quoted\"".
        if ($parentIndent >= 0 && self::matchKey($text, $this->lineNo()) === null) {
            $no = $this->lineNo();
            $this->pos++;

            return $this->parseValue($text, $parentIndent, $no);
        }

        return $this->parseMap($indent);
    }

    /**
     * Guards against hostile nesting exhausting the stack or memory.
     */
    private function enter(int $no): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new YamlError('nesting deeper than ' . self::MAX_DEPTH . ' levels; flatten the structure', $no);
        }
    }

    private function parseMap(int $indent): array
    {
        $this->enter($this->lineNo());
        try {
            return $this->parseMapBody($indent);
        } finally {
            $this->depth--;
        }
    }

    private function parseMapBody(int $indent): array
    {
        $map = [];
        $first = $this->lineNo();
        while (($p = $this->peek()) !== null) {
            [$ind, $text] = $p;
            if ($ind < $indent) {
                break;
            }
            $no = $this->lineNo();
            if ($ind > $indent) {
                throw new YamlError('unexpected indentation; align this line with its sibling keys', $no);
            }
            if (self::isListItem($text)) {
                throw new YamlError('a list item "- ..." appears where a "key: value" line is expected', $no);
            }
            $kv = self::matchKey($text, $no);
            if ($kv === null) {
                if (\preg_match('/^[^\s:#][^:\s]*:\S/', $text)) {
                    throw new YamlError('expected "key: value" but found "' . $text . '"; add a space after the colon', $no);
                }
                throw new YamlError('expected "key: value" but found "' . $text . '"', $no);
            }
            [$key, $rest] = $kv;
            if (\array_key_exists($key, $map)) {
                throw new YamlError("duplicate key \"$key\"", $no);
            }
            $this->pos++;
            $map[$key] = $this->parseValue($rest, $indent, $no);
        }

        return ['t' => 'map', 'v' => $map, 'line' => $first, 'style' => 'block'];
    }

    private function parseList(int $indent): array
    {
        $this->enter($this->lineNo());
        try {
            return $this->parseListBody($indent);
        } finally {
            $this->depth--;
        }
    }

    private function parseListBody(int $indent): array
    {
        $items = [];
        $first = $this->lineNo();
        while (($p = $this->peek()) !== null) {
            [$ind, $text] = $p;
            if ($ind !== $indent || !self::isListItem($text)) {
                if ($ind > $indent) {
                    throw new YamlError('unexpected indentation inside a list', $this->lineNo());
                }
                break;
            }
            $no = $this->lineNo();
            $rest = \ltrim(\substr($text, 1), " \t");
            $col = $indent + (\strlen($text) - \strlen($rest));
            if ($rest === '' || \str_starts_with($rest, '#')) {
                $this->pos++;
                $items[] = $this->parseNode($indent, false) ?? self::nullNode($no);
                continue;
            }
            if (self::isListItem($rest) || self::matchKey($rest, $no) !== null) {
                // The item's content starts a nested block at its own column.
                $this->lines[$this->pos]['raw'] = \str_repeat(' ', $col) . $rest;
                $items[] = self::isListItem($rest) ? $this->parseList($col) : $this->parseMap($col);
                continue;
            }
            $this->pos++;
            $items[] = $this->parseValue($rest, $indent, $no);
        }

        return ['t' => 'list', 'v' => $items, 'line' => $first, 'style' => 'block'];
    }

    private static function nullNode(int $line): array
    {
        return ['t' => 'str', 'v' => '', 'line' => $line, 'style' => 'null'];
    }

    /**
     * Splits "key: rest" (key may be quoted); null when the text is not a mapping entry.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function matchKey(string $text, int $no): ?array
    {
        if ($text === '') {
            return null;
        }
        if ($text[0] === '"' || $text[0] === "'") {
            $end = self::quotedEnd($text);
            if ($end < 0 || \preg_match('/^[ \t]*:(?:[ \t]+(.*))?$/', \substr($text, $end), $m) !== 1) {
                return null;
            }

            return [self::unquote(\substr($text, 0, $end), $no), $m[1] ?? ''];
        }
        if (\preg_match('/^([^\s#\[\]{},&*!|>%@`\'"-][^#]*?|-[^\s#][^#]*?)[ \t]*:(?:[ \t]+(.*))?$/', $text, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2] ?? ''];
    }

    /**
     * Offset just after the closing quote of a quoted scalar starting at offset 0, or -1.
     */
    private static function quotedEnd(string $s): int
    {
        $q = $s[0];
        $n = \strlen($s);
        for ($i = 1; $i < $n; $i++) {
            if ($q === '"' && $s[$i] === '\\') {
                $i++;
                continue;
            }
            if ($s[$i] === $q) {
                if ($q === "'" && ($s[$i + 1] ?? '') === "'") {
                    $i++;
                    continue;
                }

                return $i + 1;
            }
        }

        return -1;
    }

    private static function unquote(string $quoted, int $no): string
    {
        $q = $quoted[0];
        $inner = \substr($quoted, 1, -1);
        // Folding: a single line break becomes a space, each extra empty line a newline.
        $inner = \preg_replace_callback(
            // A line-ending backslash escapes the break only when it is not itself escaped,
            // so a run of backslashes splits into pairs and an optional last one.
            // Trailing blanks drop only from a line ending unescaped; before a backslash they stay.
            '/((?:\\\\\\\\)*)(\\\\?)[ \t]*\n((?:[ \t]*\n)*)[ \t]*/',
            static fn(array $m): string => $m[1] . ($q === '"' && $m[2] === '\\'
                ? \str_repeat("\n", \substr_count($m[3], "\n"))
                : $m[2] . ($m[3] === '' ? ' ' : \str_repeat("\n", \substr_count($m[3], "\n")))),
            $inner,
        ) ?? $inner;
        if ($q === "'") {
            return \str_replace("''", "'", $inner);
        }
        $map = ['0' => "\0", 'a' => "\x07", 'b' => "\x08", 't' => "\t", "\t" => "\t", 'n' => "\n", 'v' => "\x0B",
            'f' => "\f", 'r' => "\r", 'e' => "\x1B", ' ' => ' ', '"' => '"', '/' => '/', '\\' => '\\',
            'N' => "\u{85}", '_' => "\u{A0}", 'L' => "\u{2028}", 'P' => "\u{2029}"];

        return \preg_replace_callback(
            '/\\\\(x[0-9A-Fa-f]{2}|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)/s',
            static function (array $m) use ($map, $no): string {
                $e = $m[1];
                if (\strlen($e) > 1) {
                    return Text::chr((int) \hexdec(\substr($e, 1)));
                }
                if (!isset($map[$e])) {
                    throw new YamlError("invalid escape \"\\$e\" in a double-quoted string; write \"\\\\$e\" or use single quotes", $no);
                }

                return $map[$e];
            },
            $inner,
        ) ?? $inner;
    }

    private function parseValue(string $rest, int $ownerIndent, int $no): array
    {
        $rest = \rtrim($rest);
        if ($rest === '' || \str_starts_with($rest, '#')) {
            return $this->parseNode($ownerIndent, true) ?? self::nullNode($no);
        }
        $c = $rest[0];
        if ($c === '&' || $c === '!') {
            $this->notes[] = ['line' => $no, 'code' => 'yaml-anchor-tag',
                'message' => 'YAML anchor or tag "' . \strtok($rest, " \t") . '" is not supported by skills-ref (strictyaml); write the value out plainly'];
            $after = (string) \preg_replace('/^\S+[ \t]*/', '', $rest);

            return $this->parseValue($after, $ownerIndent, $no);
        }
        if ($c === '*') {
            $this->notes[] = ['line' => $no, 'code' => 'yaml-anchor-tag',
                'message' => 'YAML alias "' . $rest . '" is not supported by skills-ref (strictyaml); write the value out plainly'];

            return ['t' => 'str', 'v' => $rest, 'line' => $no, 'style' => 'alias'];
        }
        if ($c === '|' || $c === '>') {
            return $this->parseBlockScalar($rest, $ownerIndent, $no);
        }
        if ($c === '"' || $c === "'") {
            return $this->parseQuoted($rest, $no);
        }
        if ($c === '[' || $c === '{') {
            return $this->parseFlow($rest, $no);
        }
        if ($c === '@' || $c === '`' || $c === '%') {
            throw new YamlError("a plain value cannot start with \"$c\"; wrap the value in quotes", $no);
        }
        if (self::isListItem($rest)) {
            throw new YamlError('a list cannot start on the same line as its key; put each "- item" on its own line', $no);
        }
        if (\str_starts_with($rest, '? ') || \str_starts_with($rest, ': ')) {
            throw new YamlError("a plain value cannot start with \"$c \"; wrap the value in quotes", $no);
        }

        return $this->parsePlain($rest, $ownerIndent, $no);
    }

    private static function stripComment(string $s): string
    {
        if (\preg_match('/(^|[ \t])#/', $s, $m, \PREG_OFFSET_CAPTURE) === 1) {
            $s = \substr($s, 0, $m[0][1]);
        }

        return \rtrim($s);
    }

    private function parsePlain(string $first, int $ownerIndent, int $no): array
    {
        $parts = [self::stripComment($first)];
        $lineNos = [$no];
        $blank = 0;
        // Continuation lines are indented deeper than the owning key.
        while ($this->pos < \count($this->lines)) {
            $raw = $this->lines[$this->pos]['raw'];
            if (\trim($raw) === '') {
                $blank++;
                $this->pos++;
                continue;
            }
            $ind = \strspn($raw, ' ');
            $t = \substr($raw, $ind);
            if ($ind <= $ownerIndent || \str_starts_with($t, '#')) {
                break;
            }
            $parts[] = ($blank > 0 ? \str_repeat("\n", $blank) : '') . self::stripComment($t);
            $lineNos[] = $this->lines[$this->pos]['no'];
            $blank = 0;
            $this->pos++;
        }
        if ($blank > 0) {
            $this->pos -= $blank;
            // Blank lines after the value belong to whatever follows.
            while ($this->pos > 0 && \trim($this->lines[$this->pos - 1]['raw']) === '' && $blank-- > 0) {
                $this->pos--;
            }
            $this->skipBlank();
        }
        foreach ($parts as $i => $part) {
            if (\preg_match('/:(\s|$)/', $part) === 1) {
                throw new YamlError('a plain value contains ": " (or ends with ":"), which YAML reads as a nested key; wrap the whole value in quotes', $lineNos[$i]);
            }
        }
        $value = '';
        foreach ($parts as $i => $part) {
            $value .= ($i === 0 || \str_starts_with($part, "\n")) ? $part : ' ' . $part;
        }

        return ['t' => 'str', 'v' => \trim($value), 'line' => $no, 'style' => 'plain'];
    }

    private function parseQuoted(string $first, int $no): array
    {
        $buf = $first;
        while (($end = self::quotedEnd($buf)) < 0) {
            if ($this->pos >= \count($this->lines)) {
                throw new YamlError('unterminated quoted string; add the closing ' . $first[0], $no);
            }
            $buf .= "\n" . $this->lines[$this->pos]['raw'];
            $this->pos++;
        }
        $tail = \substr($buf, $end);
        if (\preg_match('/^[ \t]*(#.*)?$/', $tail) !== 1) {
            throw new YamlError('unexpected text "' . \trim($tail) . '" after the closing quote; quote the whole value', $no);
        }

        return ['t' => 'str', 'v' => self::unquote(\substr($buf, 0, $end), $no), 'line' => $no,
            'style' => $first[0] === '"' ? 'double' : 'single'];
    }

    private function parseBlockScalar(string $header, int $ownerIndent, int $no): array
    {
        if (\preg_match('/^([|>])(?:([+-])([1-9])?|([1-9])([+-])?)?[ \t]*(#.*)?$/', $header, $m) !== 1) {
            throw new YamlError("invalid block scalar header \"$header\"; use |, |-, >, or >-", $no);
        }
        $chomp = ($m[2] ?? '') !== '' ? $m[2] : ($m[5] ?? '');
        $explicit = ($m[3] ?? '') !== '' ? (int) $m[3] : (($m[4] ?? '') !== '' ? (int) $m[4] : 0);
        $contentIndent = $explicit > 0 ? \max(0, $ownerIndent) + $explicit : null;
        $lines = [];
        while ($this->pos < \count($this->lines)) {
            $raw = $this->lines[$this->pos]['raw'];
            if (\trim($raw) === '') {
                $lines[] = '';
                $this->pos++;
                continue;
            }
            $ind = \strspn($raw, ' ');
            if ($contentIndent === null) {
                if ($ind <= $ownerIndent) {
                    break;
                }
                $contentIndent = $ind;
            }
            if ($ind < $contentIndent) {
                break;
            }
            $lines[] = \substr($raw, $contentIndent);
            $this->pos++;
        }
        // Trailing blank lines are not part of the scalar's lines but may be kept by chomping.
        $trailing = 0;
        while ($lines !== [] && \end($lines) === '') {
            \array_pop($lines);
            $trailing++;
        }
        if ($m[1] === '|') {
            $value = \implode("\n", $lines);
        } else {
            $value = '';
            $prevNormal = false;
            foreach ($lines as $l) {
                if ($l === '') {
                    $value .= "\n";
                    $prevNormal = false;
                } elseif ($l[0] === ' ' || $l[0] === "\t") {
                    $value .= ($value === '' || \str_ends_with($value, "\n") ? '' : "\n") . $l . "\n";
                    $prevNormal = false;
                } else {
                    $value .= ($prevNormal ? ' ' : '') . $l;
                    $prevNormal = true;
                }
            }
            $value = \rtrim($value, "\n");
        }
        $value = match ($chomp) {
            '-' => $value,
            '+' => $value . \str_repeat("\n", $trailing + 1),
            default => $value === '' ? '' : $value . "\n",
        };

        return ['t' => 'str', 'v' => $value, 'line' => $no, 'style' => $m[1] === '|' ? 'literal' : 'folded'];
    }

    private function parseFlow(string $first, int $no): array
    {
        $this->notes[] = ['line' => $no, 'code' => 'yaml-flow-style',
            'message' => 'flow-style YAML ([...] or {...}) is rejected by skills-ref (strictyaml); rewrite it in block style'];
        $buf = $first;
        while (!self::flowBalanced($buf)) {
            if ($this->pos >= \count($this->lines)) {
                throw new YamlError('unterminated flow collection; add the closing bracket', $no);
            }
            $buf .= ' ' . \trim($this->lines[$this->pos]['raw']);
            $this->pos++;
        }
        $i = 0;
        $node = $this->flowValue($buf, $i, $no);
        if (\preg_match('/^[ \t]*(#.*)?$/', \substr($buf, $i)) !== 1) {
            throw new YamlError('unexpected text "' . \trim(\substr($buf, $i)) . '" after a flow collection', $no);
        }

        return $node;
    }

    private static function flowBalanced(string $s): bool
    {
        $depth = 0;
        $n = \strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($c === '"' || $c === "'") {
                $end = self::quotedEnd(\substr($s, $i));
                if ($end < 0) {
                    return false;
                }
                $i += $end - 1;
            } elseif ($c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ']' || $c === '}') {
                $depth--;
                if ($depth === 0) {
                    return true;
                }
            } elseif ($c === '#' && $i > 0 && ($s[$i - 1] === ' ' || $s[$i - 1] === "\t")) {
                return $depth <= 0;
            }
        }

        return $depth <= 0;
    }

    private function flowValue(string $s, int &$i, int $no): array
    {
        $this->enter($no);
        try {
            return $this->flowValueBody($s, $i, $no);
        } finally {
            $this->depth--;
        }
    }

    private function flowValueBody(string $s, int &$i, int $no): array
    {
        $n = \strlen($s);
        $skip = static function () use ($s, &$i, $n): void {
            while ($i < $n && ($s[$i] === ' ' || $s[$i] === "\t")) {
                $i++;
            }
        };
        $skip();
        $c = $s[$i] ?? '';
        if ($c === '[' || $c === '{') {
            $close = $c === '[' ? ']' : '}';
            $i++;
            $items = [];
            while (true) {
                $skip();
                if (($s[$i] ?? '') === $close) {
                    $i++;
                    break;
                }
                if ($i >= $n) {
                    throw new YamlError("unterminated flow collection; add \"$close\"", $no);
                }
                if ($c === '{') {
                    $key = $this->flowValue($s, $i, $no);
                    $skip();
                    if (($s[$i] ?? '') === ':') {
                        $i++;
                        $skip();
                        $next = $s[$i] ?? '';
                        $value = ($next === ',' || $next === '}') ? self::nullNode($no) : $this->flowValue($s, $i, $no);
                    } else {
                        $value = self::nullNode($no);
                    }
                    if ($key['t'] !== 'str') {
                        throw new YamlError('flow mapping keys must be scalars', $no);
                    }
                    if (\array_key_exists($key['v'], $items)) {
                        throw new YamlError("duplicate key \"{$key['v']}\"", $no);
                    }
                    $items[$key['v']] = $value;
                } else {
                    $items[] = $this->flowValue($s, $i, $no);
                }
                $skip();
                $d = $s[$i] ?? '';
                if ($d === ',') {
                    $i++;
                    continue;
                }
                if ($d !== $close) {
                    throw new YamlError("expected \",\" or \"$close\" in a flow collection but found \"$d\"", $no);
                }
            }

            return ['t' => $c === '[' ? 'list' : 'map', 'v' => $items, 'line' => $no, 'style' => 'flow'];
        }
        if ($c === '"' || $c === "'") {
            $end = self::quotedEnd(\substr($s, $i));
            if ($end < 0) {
                throw new YamlError("unterminated quoted string in a flow collection; add the closing $c", $no);
            }
            $node = ['t' => 'str', 'v' => self::unquote(\substr($s, $i, $end), $no), 'line' => $no, 'style' => $c === '"' ? 'double' : 'single'];
            $i += $end;

            return $node;
        }
        $start = $i;
        while ($i < $n) {
            $ch = $s[$i];
            if (\strpbrk($ch, ',[]{}') !== false) {
                break;
            }
            if ($ch === ':' && ($i + 1 >= $n || \strpbrk($s[$i + 1], " \t,]}") !== false)) {
                break;
            }
            if ($ch === '#' && $i > $start && ($s[$i - 1] === ' ' || $s[$i - 1] === "\t")) {
                break;
            }
            $i++;
        }
        $v = \trim(\substr($s, $start, $i - $start));

        return ['t' => 'str', 'v' => $v, 'line' => $no, 'style' => $v === '' ? 'null' : 'plain'];
    }
}

/**
 * A parsed SKILL.md: frontmatter node tree, body text and line offsets.
 */
final class SkillDocument
{
    /** @var array{t: string, v: mixed, line: int, style: string}|null */
    public ?array $frontmatter = null;

    public ?string $frontmatterError = null;

    public ?int $frontmatterErrorLine = null;

    public string $body = '';

    /** File line number of the first body line. */
    public int $bodyStart = 1;

    public bool $bom = false;

    /** Byte offset just past the closing delimiter line, or null without frontmatter. */
    public ?int $frontmatterEnd = null;

    /** @var list<array{line: int, code: string, message: string}> */
    public array $notes = [];

    /** Null when the document has a frontmatter block; else the problem code. */
    public ?string $missing = null;

    public static function parse(string $content): self
    {
        $doc = new self();
        if (\str_starts_with($content, "\xEF\xBB\xBF")) {
            $doc->bom = true;
            $content = \substr($content, 3);
        }
        $lines = \preg_split('/\r\n|\n|\r/', $content) ?: [];
        if (\rtrim($lines[0] ?? '') !== '---') {
            $doc->missing = 'frontmatter-missing';
            $doc->body = $content;

            return $doc;
        }
        $close = null;
        $n = \count($lines);
        for ($i = 1; $i < $n; $i++) {
            if (\rtrim($lines[$i]) === '---') {
                $close = $i;
                break;
            }
        }
        if ($close === null) {
            $doc->missing = 'frontmatter-unclosed';

            return $doc;
        }
        // Byte offset just past the closing delimiter's line break.
        \preg_match_all('/\r\n|\n|\r/', $content, $breaks, \PREG_OFFSET_CAPTURE);
        $break = $breaks[0][$close] ?? null;
        $offset = $break !== null ? $break[1] + \strlen($break[0]) : \strlen($content);
        $doc->frontmatterEnd = $offset + ($doc->bom ? 3 : 0);
        foreach ([0, $close] as $i) {
            if ($lines[$i] !== '---') {
                $doc->notes[] = ['line' => $i + 1, 'code' => 'delimiter-trailing-space',
                    'message' => '"---" delimiter has trailing whitespace, so llm/skills does not see the frontmatter (name, metadata.internal); delete the trailing spaces'];
            }
        }
        $doc->body = \implode("\n", \array_slice($lines, $close + 1));
        $doc->bodyStart = $close + 2;
        $yaml = new Yaml(\implode("\n", \array_slice($lines, 1, $close - 1)), 2);
        try {
            $doc->frontmatter = $yaml->parseDocument();
        } catch (YamlError $e) {
            $doc->frontmatterError = $e->getMessage();
            $doc->frontmatterErrorLine = $e->yamlLine;
        }
        $doc->notes = [...$doc->notes, ...$yaml->notes];

        return $doc;
    }
}
