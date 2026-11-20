<?php

/**
 * Shared code for the llm-skills helper scripts: findings report, CLI parsing,
 * path helpers, a YAML-subset parser for SKILL.md frontmatter and the skill
 * validator. Dependency-free; PHP 8.2+.
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
                \fwrite(\STDERR, $line . "\n");
            }
        } else {
            foreach ($this->items as $i) {
                $where = $i['file'] . ($i['line'] !== null ? ':' . $i['line'] : '');
                echo \str_pad($i['level'], 5), ' ', self::escape($where), ': ', self::escape($i['message']), "\n";
            }
            \printf("%d error(s), %d warning(s)\n", $this->count('ERROR'), $this->count('WARN'));
            foreach ($trailer as $line) {
                echo $line, "\n";
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

    /**
     * Real on-disk name of the skill file in a directory: "SKILL.md" or a differently cased
     * variant such as "skill.md"; null when none exists.
     */
    public static function skillFileName(string $dir): ?string
    {
        $found = null;
        foreach (self::entries($dir) as $entry) {
            if ($entry === 'SKILL.md' && \is_file("$dir/$entry")) {
                return $entry;
            }
            if ($found === null && \strcasecmp($entry, 'SKILL.md') === 0 && \is_file("$dir/$entry")) {
                $found = $entry;
            }
        }

        return $found;
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
    public static function length(string $s): int
    {
        if (\function_exists('mb_strlen')) {
            return \mb_strlen($s, 'UTF-8');
        }

        return (int) \preg_match_all('/./su', $s);
    }

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

    public static function nfkc(string $s): string
    {
        if (\class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_KC);

            return \is_string($n) ? $n : $s;
        }

        return $s;
    }

    public static function lower(string $s): string
    {
        return \function_exists('mb_strtolower') ? \mb_strtolower($s, 'UTF-8') : \strtolower($s);
    }

    /**
     * The key llm/skills compares directory names by: NFC, then simple case folding.
     */
    public static function collisionKey(string $s): string
    {
        $s = self::nfc($s);

        return \function_exists('mb_convert_case') ? \mb_convert_case($s, \MB_CASE_FOLD_SIMPLE, 'UTF-8') : \strtolower($s);
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
            '/[ \t]*(\\\\?)\n((?:[ \t]*\n)*)[ \t]*/',
            static fn(array $m): string => $q === '"' && $m[1] === '\\'
                ? \str_repeat("\n", \substr_count($m[2], "\n"))
                : $m[1] . ($m[2] === '' ? ' ' : \str_repeat("\n", \substr_count($m[2], "\n"))),
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

/**
 * Validates skill directories against the Agent Skills spec, common agent extensions and
 * llm/skills plugin behaviour.
 */
final class SkillValidator
{
    public const SPEC_KEYS = ['name', 'description', 'license', 'compatibility', 'metadata', 'allowed-tools'];

    /** Frontmatter keys some agents understand beyond the spec, with the agents that read them. */
    public const EXTENSION_KEYS = [
        'when_to_use' => ['Claude Code'],
        'argument-hint' => ['Claude Code', 'VS Code/Copilot'],
        'arguments' => ['Claude Code'],
        'disable-model-invocation' => ['Claude Code', 'Cursor', 'VS Code/Copilot'],
        'user-invocable' => ['Claude Code', 'VS Code/Copilot'],
        'disallowed-tools' => ['Claude Code'],
        'model' => ['Claude Code'],
        'effort' => ['Claude Code'],
        'context' => ['Claude Code', 'VS Code/Copilot'],
        'agent' => ['Claude Code'],
        'background' => ['Claude Code'],
        'hooks' => ['Claude Code'],
        'paths' => ['Claude Code', 'Cursor'],
        'shell' => ['Claude Code'],
        'globs' => ['Cursor'],
        'icon' => ['Cursor'],
        'color' => ['Cursor'],
    ];

    /** Directory names the walk never enters. */
    public const SKIP_DIRS = ['vendor', 'node_modules', '.git'];

    private const DEVICE_NAME = '/^(?:con|prn|aux|nul|conin\$|conout\$|com[0-9¹²³]|lpt[0-9¹²³]) *(?:\..*)?$/iuD';

    /** @var array<string, list<string>> collision key => skill directories */
    private array $names = [];

    public function __construct(private readonly Report $report)
    {
    }

    /**
     * Skill directories under a path: the path itself when it holds SKILL.md, else a walk
     * that does not descend into skills, linked duplicates, or vendor/node_modules/.git.
     *
     * @return list<string> absolute skill directories
     */
    public static function discover(string $path, int $maxDepth = 6): array
    {
        $path = Fs::abs($path);
        if (\is_file($path)) {
            return \strcasecmp(\basename($path), 'SKILL.md') === 0 ? [\dirname($path)] : [];
        }
        if (!\is_dir($path)) {
            return [];
        }
        $found = [];
        $visited = [];
        $walk = static function (string $dir, int $depth) use (&$walk, &$found, &$visited, $maxDepth): void {
            $real = \realpath($dir);
            $key = $real === false ? $dir : Fs::normalize($real);
            $key = \PHP_OS_FAMILY === 'Windows' ? \strtolower($key) : $key;
            if (isset($visited[$key])) {
                return;
            }
            $visited[$key] = true;
            if (Fs::skillFileName($dir) !== null) {
                $found[] = $dir;

                return;
            }
            if ($depth >= $maxDepth) {
                return;
            }
            foreach (Fs::entries($dir) as $entry) {
                if (\in_array($entry, self::SKIP_DIRS, true) || !\is_dir("$dir/$entry")) {
                    continue;
                }
                $walk("$dir/$entry", $depth + 1);
            }
        };
        $walk($path, 0);

        return $found;
    }

    /**
     * Validates every skill under a PATH argument; reports a PATH holding no skill.
     */
    public function validatePath(string $path, bool $explicit): void
    {
        $abs = Fs::abs($path);
        if (!\file_exists($abs)) {
            $this->report->error(Fs::rel($abs), null, 'path-missing', 'path does not exist; pass a skill directory, a SKILL.md file, or a directory that contains skills');

            return;
        }
        $skills = self::discover($abs);
        if ($skills === []) {
            $msg = 'no SKILL.md found here or below; a skill is a directory holding a SKILL.md file';
            $explicit
                ? $this->report->error(Fs::rel($abs), null, 'skill-md-missing', $msg . ' — create one or point at the right directory')
                : $this->report->warn(Fs::rel($abs), null, 'skill-md-missing', $msg . ' — pass the skills directory explicitly');

            return;
        }
        foreach ($skills as $dir) {
            $this->validateSkill($dir);
        }
        // An explicitly named container of skills may be a package's declared source.
        if ($explicit && \is_dir($abs) && !\in_array($abs, $skills, true)) {
            $this->checkSourceChildren($abs, false);
        }
    }

    /**
     * Validates a declared extra.skills.source directory as llm/skills reads it: every
     * immediate subdirectory is copied as a skill.
     *
     * @return list<array{dir: string, name: string, internal: bool}>
     */
    public function validateSource(string $dir): array
    {
        $out = [];
        foreach (Fs::entries($dir) as $entry) {
            $child = "$dir/$entry";
            if (!\is_dir($child) || $entry[0] === '.' || Fs::skillFileName($child) === null) {
                continue;
            }
            $info = $this->validateSkill($child);
            $out[] = ['dir' => $child, 'name' => $entry, 'internal' => $info['internal']];
        }
        $this->checkSourceChildren($dir, true);

        return $out;
    }

    /**
     * Reports subdirectories of a skills container that are not skills themselves.
     *
     * @param bool $declared whether the container is a declared source (catalog folders count too)
     */
    private function checkSourceChildren(string $dir, bool $declared): void
    {
        $children = \array_filter(Fs::entries($dir), static fn(string $e): bool => \is_dir("$dir/$e"));
        $hasSkill = false;
        foreach ($children as $e) {
            if (Fs::skillFileName("$dir/$e") !== null) {
                $hasSkill = true;
                break;
            }
        }
        if (!$hasSkill && !$declared) {
            return;
        }
        foreach ($children as $e) {
            $child = "$dir/$e";
            if (Fs::skillFileName($child) !== null) {
                continue;
            }
            if ($e[0] === '.') {
                if ($declared) {
                    $this->report->info(Fs::rel($child), null, 'hidden-dir-skipped', 'hidden directory in a skills source is never installed by llm/skills; rename it if it is meant to ship');
                }
                continue;
            }
            if (!$declared && self::discover($child, 2) !== []) {
                continue;
            }
            $this->report->warn(Fs::rel($child), null, 'source-subdir-not-skill', 'directory has no SKILL.md, yet llm/skills copies every subdirectory of a declared extra.skills.source as a skill; add a SKILL.md or move it out of the source directory');
        }
    }

    /**
     * Reports skill directories whose names differ but collide case-insensitively or after NFC.
     */
    public function reportCollisions(): void
    {
        foreach ($this->names as $dirs) {
            $spellings = \array_unique(\array_map('basename', $dirs));
            if (\count($spellings) < 2) {
                continue;
            }
            foreach ($dirs as $dir) {
                $others = \array_values(\array_filter($dirs, static fn(string $d): bool => \basename($d) !== \basename($dir)));
                $this->report->error(Fs::rel($dir), null, 'name-collision', \sprintf(
                    'skill directory "%s" collides with %s when compared case-insensitively/after Unicode NFC, which aborts an llm/skills sync and overwrites on case-insensitive filesystems; rename one of them',
                    \basename($dir),
                    \implode(', ', \array_map(static fn(string $d): string => '"' . Fs::rel($d) . '"', $others)),
                ));
            }
        }
    }

    /**
     * @return array{name: string|null, internal: bool, allowedTools: mixed, frontmatter: mixed}
     */
    public function validateSkill(string $dir): array
    {
        try {
            return $this->validateSkillUnguarded($dir);
        } catch (\Throwable $e) {
            $this->report->error(Fs::rel($dir), null, 'validation-failed', 'skill could not be validated (' . $e->getMessage() . '); inspect SKILL.md by hand');

            return ['name' => null, 'internal' => false, 'allowedTools' => null, 'frontmatter' => null];
        }
    }

    /**
     * @return array{name: string|null, internal: bool, allowedTools: mixed, frontmatter: mixed}
     */
    private function validateSkillUnguarded(string $dir): array
    {
        $result = ['name' => null, 'internal' => false, 'allowedTools' => null, 'frontmatter' => null];
        $dir = Fs::abs($dir);
        $base = \basename($dir);
        $relDir = Fs::rel($dir);
        $key = Text::collisionKey($base);
        $this->names[$key][] = $dir;
        $this->names[$key] = \array_values(\array_unique($this->names[$key]));

        if (($reason = self::refusedDirName($base)) !== null) {
            $this->report->error($relDir, null, 'dir-name-refused', "skill directory name \"$base\" $reason, so llm/skills refuses to install it; rename the directory (lowercase letters, digits and hyphens are safe)");
        }

        $fileName = Fs::skillFileName($dir);
        if ($fileName === null) {
            $this->report->error($relDir, null, 'skill-md-missing', 'missing required file SKILL.md; create SKILL.md with name and description frontmatter');

            return $result;
        }
        $file = "$dir/$fileName";
        $rel = Fs::rel($file);
        if ($fileName !== 'SKILL.md') {
            $this->report->warn($rel, null, 'skill-md-wrong-case', "file is named \"$fileName\", which agents on case-sensitive filesystems and llm/skills do not find; rename it to SKILL.md");
        }
        $content = @\file_get_contents($file);
        if ($content === false) {
            $this->report->error($rel, null, 'skill-md-unreadable', 'SKILL.md cannot be read; fix its permissions');

            return $result;
        }
        if (\preg_match('//u', $content) !== 1) {
            $this->report->error($rel, null, 'not-utf8', 'SKILL.md is not valid UTF-8; re-save it with UTF-8 encoding');

            return $result;
        }

        $doc = SkillDocument::parse($content);
        if ($doc->bom) {
            $this->report->warn($rel, 1, 'utf8-bom', 'file starts with a UTF-8 byte-order mark, so strict parsers such as skills-ref do not see "---" first; save it without BOM');
        }
        if ($doc->missing === 'frontmatter-missing') {
            $this->report->error($rel, 1, 'frontmatter-missing', 'SKILL.md must start with YAML frontmatter (---) on its very first line; add a --- block with name and description');
        } elseif ($doc->missing === 'frontmatter-unclosed') {
            $this->report->error($rel, 1, 'frontmatter-unclosed', 'SKILL.md frontmatter is not closed with ---; add a line containing only --- after the last frontmatter key');

            return $result;
        } elseif ($doc->frontmatterEnd !== null && $doc->frontmatterEnd > 4096) {
            $this->report->warn($rel, $doc->bodyStart - 1, 'frontmatter-over-4k', 'frontmatter ends past the first 4 KiB, which is all llm/skills reads, so it cannot see name, description or metadata.internal; shorten the frontmatter');
        }
        foreach ($doc->notes as $note) {
            $this->report->warn($rel, $note['line'], $note['code'], $note['message']);
        }
        if ($doc->frontmatterError !== null) {
            $this->report->error($rel, $doc->frontmatterErrorLine, 'yaml-invalid', 'invalid YAML in frontmatter: ' . $doc->frontmatterError);
        } elseif ($doc->frontmatter !== null) {
            $result = $this->checkFrontmatter($doc->frontmatter, $base, $rel) + $result;
        }
        $this->checkBody($doc, $dir, $rel);
        $this->checkReferenceFiles($dir);

        return $result;
    }

    /**
     * Why llm/skills refuses a skill directory name, or null when it accepts it.
     */
    public static function refusedDirName(string $name): ?string
    {
        return match (true) {
            $name === '' || $name[0] === '.' => 'is hidden',
            \preg_match('/~\d/', $name) === 1 => 'looks like a Windows short name',
            \preg_match('//u', $name) !== 1 => 'is not valid UTF-8',
            \preg_match('/[\x00-\x1f\/\\\\:<>"|?*]/', $name) === 1 => 'holds a character Windows does not allow in a directory name',
            \in_array($name[-1], ['.', ' '], true) => 'ends in a dot or space, which Windows strips',
            \preg_match(self::DEVICE_NAME, $name) === 1 => 'is a Windows device name',
            default => null,
        };
    }

    /**
     * @return array{name: string|null, internal: bool, allowedTools: mixed, frontmatter: mixed}
     */
    private function checkFrontmatter(array $root, string $dirName, string $rel): array
    {
        $r = $this->report;
        $plain = Yaml::plain($root);
        $out = ['name' => null, 'internal' => false, 'allowedTools' => null, 'frontmatter' => $plain];
        if ($root['t'] !== 'map') {
            $r->error($rel, $root['line'], 'frontmatter-not-mapping', 'SKILL.md frontmatter must be a YAML mapping of "key: value" lines; rewrite it as such');

            return $out;
        }
        /** @var array<string, array> $fields */
        $fields = $root['v'];

        // Unknown and extension keys.
        $known = [...self::SPEC_KEYS, ...\array_keys(self::EXTENSION_KEYS)];
        foreach ($fields as $key => $node) {
            $key = (string) $key;
            if (\in_array($key, self::SPEC_KEYS, true)) {
                continue;
            }
            if ($key === 'internal') {
                $r->warn($rel, $node['line'], 'internal-top-level', 'top-level "internal" is ignored by llm/skills and rejected by skills-ref; move it under metadata: as "internal: true"');
                continue;
            }
            if (isset(self::EXTENSION_KEYS[$key])) {
                $r->warn($rel, $node['line'], 'extension-key', \sprintf(
                    '"%s" is a %s extension, not an Agent Skills spec field, so other agents ignore it and skills-ref/claude.ai upload reject it; keep it only if you target %s',
                    $key,
                    \implode(' / ', self::EXTENSION_KEYS[$key]),
                    \count(self::EXTENSION_KEYS[$key]) > 1 ? 'those agents' : 'that agent',
                ));
                continue;
            }
            $guess = Text::nearest($key, $known);
            $r->warn($rel, $node['line'], 'unknown-key', $guess !== null
                ? "unknown frontmatter key \"$key\" (possible typo of \"$guess\"); rename it, since agents silently ignore unknown keys and skills-ref rejects them"
                : "unknown frontmatter key \"$key\" is ignored by agents and rejected by skills-ref; remove it or move it under metadata:");
        }

        // name
        $name = $fields['name'] ?? null;
        if ($name === null) {
            $r->error($rel, $root['line'], 'name-missing', 'missing required field "name"; add "name: ' . $dirName . '" matching the directory name');
        } elseif ($name['t'] !== 'str' || \trim($name['v']) === '' || $name['style'] === 'null') {
            $r->error($rel, $name['line'], 'name-empty', 'field "name" must be a non-empty string; set it to the directory name "' . $dirName . '"');
        } else {
            $value = Text::nfkc(\trim($name['v']));
            $out['name'] = $value;
            $line = $name['line'];
            $len = Text::length($value);
            if ($len > 64) {
                $r->error($rel, $line, 'name-too-long', "skill name exceeds the 64 character limit ($len chars); shorten it and rename the directory to match");
            }
            if (Text::lower($value) !== $value) {
                $suggest = \trim((string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower($value)), '-');
                $r->error($rel, $line, 'name-not-lowercase', "skill name \"$value\" must be lowercase; use e.g. \"$suggest\" and rename the directory to match");
            }
            if (\str_starts_with($value, '-') || \str_ends_with($value, '-')) {
                $r->error($rel, $line, 'name-hyphen-edge', "skill name \"$value\" cannot start or end with a hyphen; remove the leading/trailing hyphen");
            }
            if (\str_contains($value, '--')) {
                $r->error($rel, $line, 'name-double-hyphen', "skill name \"$value\" cannot contain consecutive hyphens; use a single hyphen");
            }
            if (\preg_match('/[^a-z0-9A-Z-]/u', $value) === 1) {
                $r->error($rel, $line, 'name-invalid-chars', "skill name \"$value\" contains invalid characters; use only lowercase letters a-z, digits and hyphens");
            }
            if (Text::nfkc($dirName) !== $value) {
                $skillDir = Fs::abs(\dirname($rel));
                // A repository or package root is installed by llm/skills under its frontmatter name.
                if (\file_exists("$skillDir/composer.json") || \file_exists("$skillDir/.git")) {
                    $r->warn($rel, $line, 'name-dir-mismatch', "directory name \"$dirName\" differs from skill name \"$value\"; llm/skills installs this repository-root skill as \"$value\", but other agents expect them to match, so rename one if the skill is also used in place");
                } else {
                    $r->error($rel, $line, 'name-dir-mismatch', "directory name \"$dirName\" must match skill name \"$value\"; rename the directory or change the name");
                }
            }
            foreach (['anthropic', 'claude'] as $word) {
                if (\str_contains(Text::lower($value), $word)) {
                    $r->warn($rel, $line, 'name-reserved-word', "skill name contains the reserved word \"$word\", which claude.ai and the Claude API reject on upload; pick a name without it if you publish there");
                }
            }
        }

        // description
        $desc = $fields['description'] ?? null;
        if ($desc === null) {
            $r->error($rel, $root['line'], 'description-missing', 'missing required field "description"; add one saying what the skill does and when to use it');
        } elseif ($desc['t'] !== 'str' || \trim($desc['v']) === '' || $desc['style'] === 'null') {
            $r->error($rel, $desc['line'], 'description-empty', 'field "description" must be a non-empty string; describe what the skill does and when to use it');
        } else {
            $len = Text::length($desc['v']);
            if ($len > 1024) {
                $r->error($rel, $desc['line'], 'description-too-long', "description exceeds the 1024 character limit ($len chars); shorten it and move detail into the body");
            }
            if (\strpbrk($desc['v'], '<>') !== false) {
                $r->warn($rel, $desc['line'], 'description-angle-brackets', 'description contains "<" or ">", which Anthropic packaging rejects and Claude Code shows escaped; rephrase without angle brackets');
            }
        }

        // compatibility
        if (isset($fields['compatibility'])) {
            $c = $fields['compatibility'];
            if ($c['t'] !== 'str') {
                $r->error($rel, $c['line'], 'compatibility-not-string', 'field "compatibility" must be a string; write the environment requirements as one sentence');
            } elseif (\trim($c['v']) === '' || $c['style'] === 'null') {
                $r->error($rel, $c['line'], 'compatibility-empty', 'field "compatibility" must not be empty; remove it or state the environment requirements');
            } elseif (($len = Text::length($c['v'])) > 500) {
                $r->error($rel, $c['line'], 'compatibility-too-long', "compatibility exceeds the 500 character limit ($len chars); shorten it");
            }
        }

        // metadata
        if (isset($fields['metadata'])) {
            $m = $fields['metadata'];
            if ($m['t'] !== 'map') {
                if (!($m['t'] === 'str' && $m['style'] === 'null')) {
                    $hint = $m['t'] === 'str' && \preg_match('/^[\w-]+:\S/', $m['v']) === 1 ? ' (add a space after the colon in "' . $m['v'] . '")' : '';
                    $r->error($rel, $m['line'], 'metadata-not-mapping', 'field "metadata" must be a mapping of string keys to string values; write it as indented "key: value" lines' . $hint);
                }
            } else {
                foreach ($m['v'] as $k => $v) {
                    if ($v['t'] !== 'str') {
                        $r->warn($rel, $v['line'], 'metadata-value-not-string', "metadata.$k holds a nested " . ($v['t'] === 'map' ? 'mapping' : 'list') . ', but metadata values must be strings; flatten it to a string');
                    }
                }
                if (isset($m['v']['internal'])) {
                    $iv = $m['v']['internal'];
                    $val = $iv['t'] === 'str' ? \strtolower(\trim($iv['v'])) : '';
                    if (\in_array($val, ['true', '1'], true)) {
                        $out['internal'] = true;
                    } elseif ($val !== 'false' && $val !== '0') {
                        $r->warn($rel, $iv['line'], 'internal-value-ignored', 'llm/skills treats metadata.internal as set only when it is true or 1; write "internal: true" or remove it');
                    }
                }
            }
        }

        // license
        if (isset($fields['license']) && $fields['license']['t'] !== 'str') {
            $r->warn($rel, $fields['license']['line'], 'license-not-string', 'field "license" should be a short string such as "MIT" or "Proprietary. LICENSE.txt has complete terms"; rewrite it as one');
        }

        // allowed-tools
        if (isset($fields['allowed-tools'])) {
            $t = $fields['allowed-tools'];
            $out['allowedTools'] = Yaml::plain($t);
            if ($t['t'] === 'list') {
                $r->warn($rel, $t['line'], 'allowed-tools-list', 'allowed-tools is a YAML list, which only some agents accept; the spec form is one space-separated string such as "Bash(git:*) Read"');
            } elseif ($t['t'] === 'map') {
                $r->error($rel, $t['line'], 'allowed-tools-invalid', 'allowed-tools must be a space-separated string of tool names; rewrite it as e.g. "Bash(git:*) Read"');
            }
        }

        return $out;
    }

    /**
     * Blanks out fenced and indented code blocks and HTML comments, keeping line numbering.
     *
     * @return list<string>
     */
    private static function proseLines(string $text): array
    {
        $out = [];
        $fence = null;
        $inComment = false;
        $inIndented = false;
        $prevBlank = true;
        $inList = false;
        foreach (\preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            if ($fence === null) {
                $clean = '';
                $rest = $line;
                while (true) {
                    if ($inComment) {
                        $end = \strpos($rest, '-->');
                        if ($end === false) {
                            break;
                        }
                        $rest = \substr($rest, $end + 3);
                        $inComment = false;
                    }
                    $start = \strpos($rest, '<!--');
                    if ($start === false) {
                        $clean .= $rest;
                        break;
                    }
                    $clean .= \substr($rest, 0, $start);
                    $rest = \substr($rest, $start + 4);
                    $inComment = true;
                }
                $line = $clean;
                $blank = \trim($line) === '';
                // Four-space indented code starts after a blank line, unless it continues a list item.
                if (!$blank && \preg_match('/^(?: {4}|\t)/', $line) === 1 && ($inIndented || ($prevBlank && !$inList))) {
                    $inIndented = true;
                    $prevBlank = false;
                    $out[] = '';
                    continue;
                }
                if (!$blank) {
                    $inIndented = false;
                    $inList = \preg_match('/^\s*(?:[-*+]|\d+[.)])\s/', $line) === 1 || ($inList && \preg_match('/^\s/', $line) === 1);
                }
                $prevBlank = $blank;
            }
            if (\preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                if ($fence === null) {
                    $fence = $m[1];
                    $out[] = '';
                    continue;
                }
                if ($m[1][0] === $fence[0] && \strlen($m[1]) >= \strlen($fence) && \trim($line) === $m[1]) {
                    $fence = null;
                    $out[] = '';
                    continue;
                }
            }
            $out[] = $fence === null ? $line : '';
        }

        return $out;
    }

    /**
     * Relative link targets in Markdown prose (inline links, images, reference definitions).
     *
     * @return list<array{line: int, target: string}>
     */
    private static function links(string $text, int $firstLine): array
    {
        $links = [];
        foreach (self::proseLines($text) as $i => $line) {
            $noCode = \preg_replace('/(`+)(?:(?!\1).)+\1/', '', $line) ?? $line;
            $targets = [];
            if (\preg_match_all('/!?\[(?:[^\[\]]|\[[^\]]*\])*\]\(\s*(<[^>]*>|[^()\s]*(?:\([^()\s]*\)[^()\s]*)*)(?:\s+(?:"[^"]*"|\'[^\']*\'|\([^)]*\)))?\s*\)/', $noCode, $m) > 0) {
                $targets = $m[1];
            }
            // Reference definitions; "[^1]:" is a footnote, not a link.
            if (\preg_match('/^\s{0,3}\[(?!\^)[^\]]+\]:\s*(\S+)/', $noCode, $m) === 1) {
                $targets[] = $m[1];
            }
            foreach ($targets as $t) {
                $t = \trim($t, '<>');
                if ($t !== '') {
                    $links[] = ['line' => $firstLine + $i, 'target' => $t];
                }
            }
        }

        return $links;
    }

    /**
     * Resolves a link target to a path inside or near the skill, or null when it is not a local file link.
     */
    private static function resolveLink(string $target, string $fromDir): ?string
    {
        if (\preg_match('~^(?:[a-z][a-z0-9+.-]*:|#|//|/)~i', $target) === 1 || \strpbrk($target, '{}$*') !== false) {
            return null;
        }
        $path = (string) \preg_replace('/[#?].*$/s', '', $target);
        if ($path === '') {
            return null;
        }

        return Fs::abs(\str_replace('\\', '/', \rawurldecode($path)), $fromDir);
    }

    private static function inside(string $path, string $dir): bool
    {
        $fold = \PHP_OS_FAMILY === 'Windows' ? 'strtolower' : static fn(string $s): string => $s;

        return $fold($path) === $fold($dir) || \str_starts_with($fold($path), $fold(\rtrim($dir, '/') . '/'));
    }

    /**
     * Checks one Markdown file's links; returns the in-skill .md files it links to.
     *
     * @return list<array{line: int, path: string}>
     */
    private function checkLinks(string $text, int $firstLine, string $file, string $skillDir, bool $inlinePaths): array
    {
        $rel = Fs::rel($file);
        $mdLinks = [];
        foreach (self::links($text, $firstLine) as $link) {
            $target = $link['target'];
            $resolved = self::resolveLink($target, \dirname($file));
            if ($resolved === null) {
                continue;
            }
            if (\str_contains($target, '\\')) {
                $this->report->warn($rel, $link['line'], 'link-backslash', "link \"$target\" uses backslashes, which break on non-Windows systems; use forward slashes");
            }
            if (!\file_exists($resolved)) {
                $this->report->error($rel, $link['line'], 'link-missing', "link target \"$target\" does not exist; fix the path or add the file");
                continue;
            }
            if (!self::inside($resolved, $skillDir)) {
                $this->report->info($rel, $link['line'], 'link-outside-skill', "link \"$target\" leaves the skill directory, so it resolves only where that file is installed alongside; copy the file into the skill if it must always be available");
                continue;
            }
            if (\str_ends_with(\strtolower($resolved), '.md') && \is_file($resolved)) {
                $mdLinks[] = ['line' => $link['line'], 'path' => $resolved];
            }
        }

        // Inline-code paths such as `references/api.md` or `scripts/run.py`, checked in SKILL.md only:
        // reference files often name paths of the upstream repository instead.
        foreach ($inlinePaths ? self::proseLines($text) : [] as $i => $line) {
            if (\preg_match_all('/(`+)((?:(?!\1).)+)\1/', $line, $spans) === 0) {
                continue;
            }
            foreach ($spans[2] as $span) {
                if (\preg_match_all('~(?<![\w./\\\\-])(?:\./)?(?:references|scripts|assets)([/\\\\])[\w.\\\\/-]*[\w-]~', $span, $pm, \PREG_SET_ORDER) === 0) {
                    continue;
                }
                foreach ($pm as $p) {
                    $path = $p[0];
                    if (\str_contains($path, '\\')) {
                        $this->report->warn($rel, $firstLine + $i, 'path-backslash', "path \"$path\" uses backslashes, which break on non-Windows systems; use forward slashes");
                        $path = \str_replace('\\', '/', $path);
                    }
                    $abs = Fs::abs($path, $skillDir);
                    $last = \basename($path);
                    if (!\str_contains($last, '.') || \file_exists($abs) || \file_exists(Fs::abs($path, \dirname($file)))) {
                        continue;
                    }
                    // Prose often names a file of a sibling skill ("see other-skill → references/x.md").
                    if ((\glob(\dirname($skillDir) . '/*/' . \ltrim($path, './'), \GLOB_NOSORT) ?: []) !== []) {
                        continue;
                    }
                    $this->report->warn($rel, $firstLine + $i, 'path-missing', "path \"$path\" is not present in the skill directory; fix the path or add the file");
                }
            }
        }

        return $mdLinks;
    }

    private function checkBody(SkillDocument $doc, string $dir, string $rel): void
    {
        $lines = $doc->missing === null ? \count(\preg_split('/\r\n|\n|\r/', \rtrim($doc->body)) ?: []) : 0;
        if ($lines > 500) {
            $this->report->warn($rel, null, 'body-too-long', "SKILL.md body has $lines lines, over the recommended 500; move detail into reference files linked from SKILL.md");
        }
        $tokens = intdiv(Text::length($doc->body), 4);
        if ($tokens > 5000) {
            $this->report->warn($rel, null, 'body-too-many-tokens', "SKILL.md body is about $tokens tokens, over the recommended 5000; move detail into reference files linked from SKILL.md");
        }
        $level1 = $this->checkLinks($doc->body, $doc->bodyStart, "$dir/SKILL.md", $dir, true);
        $direct = [];
        foreach ($level1 as $l) {
            $direct[\strtolower($l['path'])] = true;
        }
        $skillMd = \strtolower(Fs::abs("$dir/SKILL.md"));
        $checked = [];
        foreach ($level1 as $l) {
            $key = \strtolower($l['path']);
            if ($key === $skillMd || isset($checked[$key])) {
                continue;
            }
            $checked[$key] = true;
            $text = (string) @\file_get_contents($l['path']);
            foreach ($this->checkLinks($text, 1, $l['path'], $dir, false) as $deeper) {
                $dkey = \strtolower($deeper['path']);
                if ($dkey === $skillMd || $dkey === $key || isset($direct[$dkey])) {
                    continue;
                }
                $this->report->warn(Fs::rel($l['path']), $deeper['line'], 'reference-nested', \sprintf(
                    'links to "%s", which SKILL.md does not link directly; agents may read nested references only partially, so link it from SKILL.md (references one level deep)',
                    Fs::rel($deeper['path']),
                ));
            }
        }
    }

    /**
     * Long reference Markdown files should open with a table of contents.
     */
    private function checkReferenceFiles(string $dir): void
    {
        $files = [];
        $walk = static function (string $d, int $depth) use (&$walk, &$files): void {
            foreach (Fs::entries($d) as $e) {
                $p = "$d/$e";
                if (\is_dir($p)) {
                    if ($depth < 4 && $e[0] !== '.' && !\in_array($e, ['assets', 'scripts', ...self::SKIP_DIRS], true) && !Fs::isLink($p)) {
                        $walk($p, $depth + 1);
                    }
                } elseif (\str_ends_with(\strtolower($e), '.md') && ($depth > 0 || \strcasecmp($e, 'SKILL.md') !== 0)) {
                    $files[] = $p;
                }
            }
        };
        $walk($dir, 0);
        foreach ($files as $f) {
            $text = (string) @\file_get_contents($f);
            $lines = \preg_split('/\r\n|\n|\r/', \rtrim($text)) ?: [];
            if (\count($lines) <= 100 || self::hasToc($lines)) {
                continue;
            }
            $this->report->warn(Fs::rel($f), null, 'reference-no-toc', \sprintf(
                'reference file has %d lines but no table of contents near the top; add a short "Contents" list of its sections so agents see the whole scope from a partial read',
                \count($lines),
            ));
        }
    }

    /**
     * @param list<string> $lines
     */
    private static function hasToc(array $lines): bool
    {
        $head = \array_slice($lines, 0, 50);
        $anchorItems = 0;
        $listItems = [];
        foreach ($head as $line) {
            if (\preg_match('/^\s*(?:#{1,6}\s*|\*\*|__)?(?:table of contents|contents|toc|on this page|in this (?:file|document|reference)|sections|index)\b/i', $line) === 1) {
                return true;
            }
            if (\preg_match('/^\s*(?:[-*+]|\d+\.)\s+(.*)$/', $line, $m) === 1) {
                if (\str_contains($m[1], '](#')) {
                    $anchorItems++;
                }
                $listItems[] = self::plainText($m[1]);
            }
        }
        if ($anchorItems >= 3) {
            return true;
        }
        // An early list naming at least three of the file's headings works as a contents list.
        $headings = [];
        foreach ($lines as $line) {
            if (\preg_match('/^#{2,6}\s+(.*)$/', $line, $m) === 1) {
                $headings[self::plainText($m[1])] = true;
            }
        }
        $hits = 0;
        foreach ($listItems as $item) {
            if ($item !== '' && isset($headings[$item])) {
                $hits++;
            }
        }

        return $hits >= 3;
    }

    private static function plainText(string $s): string
    {
        $s = (string) \preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $s);
        $s = (string) \preg_replace('/[`*_]/', '', $s);
        $s = (string) \preg_replace('/\s+[—–-]\s+.*$/u', '', $s);

        return \strtolower(\trim($s, " \t:."));
    }
}
