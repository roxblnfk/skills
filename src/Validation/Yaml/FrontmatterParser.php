<?php

declare(strict_types=1);

namespace LLM\Skills\Validation\Yaml;

use LLM\Skills\Validation\ParseNote;
use LLM\Skills\Validation\Text;

/**
 * Parser for the YAML subset frontmatter uses: block mappings and sequences at any depth,
 * plain, single- and double-quoted scalars (also multi-line), literal and folded block
 * scalars, and flow sequences and mappings.
 *
 * Flow collections, anchors, aliases and tags parse but leave a {@see ParseNote}, since
 * strictyaml, which the reference validator `skills-ref` uses, rejects them.
 *
 * @internal
 */
final class FrontmatterParser
{
    /**
     * Nesting limit; deeper input is hostile or broken and would exhaust the stack.
     */
    private const MAX_DEPTH = 64;

    /** Characters in a flow collection after which a value, possibly quoted, begins. */
    private const VALUE_OPENERS = ['[', '{', ',', ':'];

    /** @var list<array{raw: string, no: int}> */
    private array $lines = [];

    private int $pos = 0;
    private int $depth = 0;

    /** @var list<ParseNote> */
    private array $notes = [];

    /**
     * @param int $firstLine file line number of the first frontmatter line
     *
     * @psalm-mutation-free
     */
    public function __construct(string $text, int $firstLine)
    {
        foreach (\preg_split('/\r\n|\n|\r/', $text) ?: [] as $i => $raw) {
            $this->lines[] = ['raw' => $raw, 'no' => $firstLine + $i];
        }
    }

    /**
     * @throws YamlSyntaxError
     */
    public function parseDocument(): YamlNode
    {
        $node = $this->parseNode(-1, false);
        $this->skipBlank();
        if ($this->pos < \count($this->lines)) {
            throw new YamlSyntaxError(
                'unexpected content "' . \trim($this->lines[$this->pos]['raw']) . '"',
                $this->lines[$this->pos]['no'],
            );
        }

        return $node ?? YamlNode::map([], $this->lines[0]['no'] ?? 1, NodeStyle::Block);
    }

    /**
     * @return list<ParseNote>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @psalm-pure
     */
    private static function isListItem(string $text): bool
    {
        return $text === '-' || \str_starts_with($text, '- ') || \str_starts_with($text, "-\t");
    }

    /**
     * Splits `key: rest`, where the key may be quoted; `null` when the text is not a mapping entry.
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
        if (\preg_match(
            '/^([^\s#\[\]{},&*!|>%@`\'"-][^#]*?|-[^\s#][^#]*?)[ \t]*:(?:[ \t]+(.*))?$/',
            $text,
            $m,
        ) !== 1) {
            return null;
        }

        return [$m[1], $m[2] ?? ''];
    }

    /**
     * Offset just past the closing quote of a quoted scalar that starts at offset 0, or -1.
     *
     * @psalm-pure
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

    /**
     * @throws YamlSyntaxError on an escape sequence YAML does not define
     */
    private static function unquote(string $quoted, int $no): string
    {
        $q = $quoted[0];
        $inner = \substr($quoted, 1, -1);
        // Line folding: a single break becomes a space, each further empty line a newline.
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
        $map = [
            '0' => "\0", 'a' => "\x07", 'b' => "\x08", 't' => "\t", "\t" => "\t", 'n' => "\n",
            'v' => "\x0B", 'f' => "\f", 'r' => "\r", 'e' => "\x1B", ' ' => ' ', '"' => '"',
            '/' => '/', '\\' => '\\', 'N' => "\u{85}", '_' => "\u{A0}", 'L' => "\u{2028}", 'P' => "\u{2029}",
        ];

        return \preg_replace_callback(
            '/\\\\(x[0-9A-Fa-f]{2}|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)/s',
            static function (array $m) use ($map, $no): string {
                $e = $m[1];
                if (\strlen($e) > 1) {
                    return Text::chr((int) \hexdec(\substr($e, 1)));
                }
                if (!isset($map[$e])) {
                    throw new YamlSyntaxError(
                        "invalid escape \"\\$e\" in a double-quoted string; write \"\\\\$e\" or use single quotes",
                        $no,
                    );
                }

                return $map[$e];
            },
            $inner,
        ) ?? $inner;
    }

    /**
     * @psalm-pure
     */
    private static function stripComment(string $s): string
    {
        if (\preg_match('/(^|[ \t])#/', $s, $m, \PREG_OFFSET_CAPTURE) === 1) {
            $s = \substr($s, 0, $m[0][1]);
        }

        return \rtrim($s);
    }

    /**
     * A flow collection gathered over several lines as one line: each comment cut off at its
     * line end, line breaks outside quoted scalars turned into spaces.
     *
     * @psalm-pure
     */
    private static function stripFlowComments(string $s): string
    {
        $out = '';
        $n = \strlen($s);
        $valueStart = true;
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($c === '#' && ($i === 0 || \in_array($s[$i - 1], [' ', "\t", "\n"], true))) {
                $lineEnd = \strpos($s, "\n", $i);
                if ($lineEnd === false) {
                    break;
                }
                $i = $lineEnd;
                $c = "\n";
            }
            if ($c === "\n" || $c === ' ' || $c === "\t") {
                $out .= $c === "\n" ? ' ' : $c;
                continue;
            }
            $opensValue = $valueStart;
            $valueStart = \in_array($c, self::VALUE_OPENERS, true);
            if (($c === '"' || $c === "'") && $opensValue) {
                $end = self::quotedEnd(\substr($s, $i));
                if ($end < 0) {
                    return $out . \substr($s, $i);
                }
                $out .= \substr($s, $i, $end);
                $i += $end - 1;
                continue;
            }
            $out .= $c;
        }

        return $out;
    }

    /**
     * @psalm-pure
     */
    private static function flowBalanced(string $s): bool
    {
        $depth = 0;
        $n = \strlen($s);
        // A quote opens a quoted scalar only where a value starts; inside a plain scalar
        // such as "it's" it is an ordinary character.
        $valueStart = true;
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($c === ' ' || $c === "\t") {
                continue;
            }
            $opensValue = $valueStart;
            $valueStart = \in_array($c, self::VALUE_OPENERS, true);
            if (($c === '"' || $c === "'") && $opensValue) {
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

    /**
     * @psalm-external-mutation-free
     */
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
     *
     * @throws YamlSyntaxError on a tab in the indentation
     *
     * @psalm-external-mutation-free
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
            throw new YamlSyntaxError(
                'tab character used for indentation; indent with spaces only',
                $this->lines[$this->pos]['no'],
            );
        }

        return [$indent, \substr($raw, $indent)];
    }

    /**
     * @psalm-mutation-free
     */
    private function lineNo(): int
    {
        return $this->lines[\min($this->pos, \count($this->lines) - 1)]['no'] ?? 1;
    }

    /**
     * @throws YamlSyntaxError
     */
    private function parseNode(int $parentIndent, bool $allowSameIndentList): ?YamlNode
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
     * @throws YamlSyntaxError past {@see self::MAX_DEPTH}
     *
     * @psalm-external-mutation-free
     */
    private function enter(int $no): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new YamlSyntaxError('nesting deeper than ' . self::MAX_DEPTH . ' levels; flatten the structure', $no);
        }
    }

    /**
     * @throws YamlSyntaxError
     */
    private function parseMap(int $indent): YamlNode
    {
        $this->enter($this->lineNo());
        try {
            return $this->parseMapBody($indent);
        } finally {
            $this->depth--;
        }
    }

    /**
     * @throws YamlSyntaxError
     */
    private function parseMapBody(int $indent): YamlNode
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
                throw new YamlSyntaxError('unexpected indentation; align this line with its sibling keys', $no);
            }
            if (self::isListItem($text)) {
                throw new YamlSyntaxError('a list item "- ..." appears where a "key: value" line is expected', $no);
            }
            $kv = self::matchKey($text, $no);
            if ($kv === null) {
                if (\preg_match('/^[^\s:#][^:\s]*:\S/', $text) === 1) {
                    throw new YamlSyntaxError(
                        'expected "key: value" but found "' . $text . '"; add a space after the colon',
                        $no,
                    );
                }
                throw new YamlSyntaxError('expected "key: value" but found "' . $text . '"', $no);
            }
            [$key, $rest] = $kv;
            if (\array_key_exists($key, $map)) {
                throw new YamlSyntaxError("duplicate key \"$key\"", $no);
            }
            $this->pos++;
            $map[$key] = $this->parseValue($rest, $indent, $no);
        }

        return YamlNode::map($map, $first, NodeStyle::Block);
    }

    /**
     * @throws YamlSyntaxError
     */
    private function parseList(int $indent): YamlNode
    {
        $this->enter($this->lineNo());
        try {
            return $this->parseListBody($indent);
        } finally {
            $this->depth--;
        }
    }

    /**
     * @throws YamlSyntaxError
     */
    private function parseListBody(int $indent): YamlNode
    {
        $items = [];
        $first = $this->lineNo();
        while (($p = $this->peek()) !== null) {
            [$ind, $text] = $p;
            if ($ind !== $indent || !self::isListItem($text)) {
                if ($ind > $indent) {
                    throw new YamlSyntaxError('unexpected indentation inside a list', $this->lineNo());
                }
                break;
            }
            $no = $this->lineNo();
            $rest = \ltrim(\substr($text, 1), " \t");
            $col = $indent + (\strlen($text) - \strlen($rest));
            if ($rest === '' || \str_starts_with($rest, '#')) {
                $this->pos++;
                $items[] = $this->parseNode($indent, false) ?? YamlNode::null($no);
                continue;
            }
            if (self::isListItem($rest) || self::matchKey($rest, $no) !== null) {
                // The item's content opens a nested block at its own column.
                \array_splice($this->lines, $this->pos, 1, [['raw' => \str_repeat(' ', $col) . $rest, 'no' => $no]]);
                $items[] = self::isListItem($rest) ? $this->parseList($col) : $this->parseMap($col);
                continue;
            }
            $this->pos++;
            $items[] = $this->parseValue($rest, $indent, $no);
        }

        return YamlNode::list($items, $first, NodeStyle::Block);
    }

    /**
     * @throws YamlSyntaxError
     */
    private function parseValue(string $rest, int $ownerIndent, int $no): YamlNode
    {
        $rest = \rtrim($rest);
        if ($rest === '' || \str_starts_with($rest, '#')) {
            return $this->parseNode($ownerIndent, true) ?? YamlNode::null($no);
        }
        $c = $rest[0];
        if ($c === '&' || $c === '!') {
            $this->notes[] = new ParseNote(
                $no,
                'yaml-anchor-tag',
                'YAML anchor or tag "' . (string) \strtok($rest, " \t")
                . '" is not supported by skills-ref (strictyaml); write the value out plainly',
            );
            // All properties in one linear pass: a line of thousands of them must neither
            // recurse nor overrun the PCRE backtracking limit.
            $i = 0;
            $n = \strlen($rest);
            while ($i < $n && ($rest[$i] === '&' || $rest[$i] === '!')) {
                $i += \strcspn($rest, " \t", $i);
                $i += \strspn($rest, " \t", $i);
            }

            return $this->parseValue(\substr($rest, $i), $ownerIndent, $no);
        }
        if ($c === '*') {
            $this->notes[] = new ParseNote(
                $no,
                'yaml-anchor-tag',
                'YAML alias "' . $rest . '" is not supported by skills-ref (strictyaml); write the value out plainly',
            );

            return YamlNode::scalar($rest, $no, NodeStyle::Alias);
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
            throw new YamlSyntaxError("a plain value cannot start with \"$c\"; wrap the value in quotes", $no);
        }
        if (self::isListItem($rest)) {
            throw new YamlSyntaxError(
                'a list cannot start on the same line as its key; put each "- item" on its own line',
                $no,
            );
        }
        if (\str_starts_with($rest, '? ') || \str_starts_with($rest, ': ')) {
            throw new YamlSyntaxError("a plain value cannot start with \"$c \"; wrap the value in quotes", $no);
        }

        return $this->parsePlain($rest, $ownerIndent, $no);
    }

    /**
     * @throws YamlSyntaxError
     *
     * @psalm-external-mutation-free
     */
    private function parsePlain(string $first, int $ownerIndent, int $no): YamlNode
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
                throw new YamlSyntaxError(
                    'a plain value contains ": " (or ends with ":"), which YAML reads as a nested key; '
                    . 'wrap the whole value in quotes',
                    $lineNos[$i],
                );
            }
        }
        $value = '';
        foreach ($parts as $i => $part) {
            $value .= ($i === 0 || \str_starts_with($part, "\n")) ? $part : ' ' . $part;
        }

        return YamlNode::scalar(\trim($value), $no, NodeStyle::Plain);
    }

    /**
     * @throws YamlSyntaxError
     */
    private function parseQuoted(string $first, int $no): YamlNode
    {
        $buf = $first;
        $end = self::quotedEnd($buf);
        while ($end < 0) {
            if ($this->pos >= \count($this->lines)) {
                throw new YamlSyntaxError('unterminated quoted string; add the closing ' . $first[0], $no);
            }
            $buf .= "\n" . $this->lines[$this->pos]['raw'];
            $this->pos++;
            $end = self::quotedEnd($buf);
        }
        $tail = \substr($buf, $end);
        if (\preg_match('/^[ \t]*(#.*)?$/', $tail) !== 1) {
            throw new YamlSyntaxError(
                'unexpected text "' . \trim($tail) . '" after the closing quote; quote the whole value',
                $no,
            );
        }

        return YamlNode::scalar(
            self::unquote(\substr($buf, 0, $end), $no),
            $no,
            $first[0] === '"' ? NodeStyle::DoubleQuoted : NodeStyle::SingleQuoted,
        );
    }

    /**
     * @throws YamlSyntaxError
     *
     * @psalm-external-mutation-free
     */
    private function parseBlockScalar(string $header, int $ownerIndent, int $no): YamlNode
    {
        if (\preg_match('/^([|>])(?:([+-])([1-9])?|([1-9])([+-])?)?[ \t]*(#.*)?$/', $header, $m) !== 1) {
            throw new YamlSyntaxError("invalid block scalar header \"$header\"; use |, |-, >, or >-", $no);
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
        // Trailing blank lines are not content, though "+" chomping keeps them.
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

        return YamlNode::scalar($value, $no, $m[1] === '|' ? NodeStyle::Literal : NodeStyle::Folded);
    }

    /**
     * @throws YamlSyntaxError
     */
    private function parseFlow(string $first, int $no): YamlNode
    {
        $this->notes[] = new ParseNote(
            $no,
            'yaml-flow-style',
            'flow-style YAML ([...] or {...}) is rejected by skills-ref (strictyaml); rewrite it in block style',
        );
        $buf = $first;
        while (!self::flowBalanced(self::stripFlowComments($buf))) {
            if ($this->pos >= \count($this->lines)) {
                throw new YamlSyntaxError('unterminated flow collection; add the closing bracket', $no);
            }
            $buf .= "\n" . \trim($this->lines[$this->pos]['raw']);
            $this->pos++;
        }
        $buf = self::stripFlowComments($buf);
        $i = 0;
        $node = $this->flowValue($buf, $i, $no);
        if (\preg_match('/^[ \t]*(#.*)?$/', \substr($buf, $i)) !== 1) {
            throw new YamlSyntaxError(
                'unexpected text "' . \trim(\substr($buf, $i)) . '" after a flow collection',
                $no,
            );
        }

        return $node;
    }

    /**
     * @throws YamlSyntaxError
     */
    private function flowValue(string $s, int &$i, int $no): YamlNode
    {
        $this->enter($no);
        try {
            return $this->flowValueBody($s, $i, $no);
        } finally {
            $this->depth--;
        }
    }

    /**
     * @throws YamlSyntaxError
     */
    private function flowValueBody(string $s, int &$i, int $no): YamlNode
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
                    throw new YamlSyntaxError("unterminated flow collection; add \"$close\"", $no);
                }
                if ($c === '{') {
                    $key = $this->flowValue($s, $i, $no);
                    $skip();
                    if (($s[$i] ?? '') === ':') {
                        $i++;
                        $skip();
                        $next = $s[$i] ?? '';
                        $value = ($next === ',' || $next === '}') ? YamlNode::null($no) : $this->flowValue($s, $i, $no);
                    } else {
                        $value = YamlNode::null($no);
                    }
                    if ($key->kind !== NodeKind::Scalar) {
                        throw new YamlSyntaxError('flow mapping keys must be scalars', $no);
                    }
                    if (\array_key_exists($key->scalar, $items)) {
                        throw new YamlSyntaxError("duplicate key \"{$key->scalar}\"", $no);
                    }
                    $items[$key->scalar] = $value;
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
                    throw new YamlSyntaxError(
                        "expected \",\" or \"$close\" in a flow collection but found \"$d\"",
                        $no,
                    );
                }
            }

            return $c === '['
                ? YamlNode::list(\array_values($items), $no, NodeStyle::Flow)
                : YamlNode::map($items, $no, NodeStyle::Flow);
        }
        if ($c === '"' || $c === "'") {
            $end = self::quotedEnd(\substr($s, $i));
            if ($end < 0) {
                throw new YamlSyntaxError("unterminated quoted string in a flow collection; add the closing $c", $no);
            }
            $node = YamlNode::scalar(
                self::unquote(\substr($s, $i, $end), $no),
                $no,
                $c === '"' ? NodeStyle::DoubleQuoted : NodeStyle::SingleQuoted,
            );
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

        return $v === '' ? YamlNode::null($no) : YamlNode::scalar($v, $no, NodeStyle::Plain);
    }
}
