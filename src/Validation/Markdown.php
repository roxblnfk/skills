<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

/**
 * The bits of Markdown the validator reads: prose lines and link targets.
 *
 * @internal
 *
 * @psalm-pure
 */
final class Markdown
{
    /**
     * The lines of `$text` with fenced and indented code blocks and HTML comments blanked
     * out, so line numbers stay those of the file.
     *
     * @return list<string>
     *
     * @psalm-pure
     */
    public static function proseLines(string $text): array
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
                    $inList = \preg_match('/^\s*(?:[-*+]|\d+[.)])\s/', $line) === 1
                        || ($inList && \preg_match('/^\s/', $line) === 1);
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
     * Link targets in prose: inline links, images and reference definitions.
     *
     * @param int $firstLine file line number of the first line of `$text`
     *
     * @return list<array{line: int, target: non-empty-string}>
     *
     * @psalm-pure
     */
    public static function links(string $text, int $firstLine): array
    {
        $links = [];
        foreach (self::proseLines($text) as $i => $line) {
            $noCode = \preg_replace('/(`+)(?:(?!\1).)+\1/', '', $line) ?? $line;
            $targets = [];
            if (\preg_match_all(
                '/!?\[(?:[^\[\]]|\[[^\]]*\])*\]\(\s*(<[^>]*>|[^()\s]*(?:\([^()\s]*\)[^()\s]*)*)'
                . '(?:\s+(?:"[^"]*"|\'[^\']*\'|\([^)]*\)))?\s*\)/',
                $noCode,
                $m,
            ) > 0) {
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
}
