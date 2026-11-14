<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use Internal\Path;

/**
 * Reads the leading YAML-style frontmatter from a skill's `SKILL.md`.
 *
 * Frontmatter is the block delimited by two `---` lines at the very
 * start of the file:
 *
 *     ---
 *     name: greeting
 *     description: Reply with a friendly greeting.
 *     ---
 *
 *     # Body content begins here.
 *
 * The parser is deliberately minimal: it understands top-level `key: value`
 * pairs, with optional surrounding `"` or `'` on the value and a trailing
 * `# comment`. Multi-line scalars, sequences and anchors are out of scope;
 * the first occurrence of a key wins.
 *
 * A top-level mapping is read one level deep, in block or flow style, and
 * each of its keys is returned as `parent.child`, so a nested key never
 * replaces a top-level one of its name. Both of these yield
 * `metadata.internal => 'true'`:
 *
 *     metadata:                     metadata: {internal: true}
 *       internal: true
 *
 * A flow mapping must fit on one line and hold only scalars; deeper levels
 * are skipped.
 *
 * Reading is best-effort:
 *
 * - missing file → `null`
 * - present file without a frontmatter block → `null`
 * - present block with no parseable lines → `null`
 * - any other I/O failure → `null`
 *
 * Callers (currently `skills:show`) treat a `null` description as
 * "unknown" and render the row without a second column.
 */
final readonly class SkillFrontmatterReader
{
    /**
     * Cap how many bytes we ever read from a `SKILL.md` just to look at
     * the top. 4 KiB is enough for any reasonable frontmatter block; the
     * cap stops us from ever pulling a multi-megabyte rich-content
     * skill file into memory.
     */
    private const READ_CAP_BYTES = 4096;

    /**
     * Whether the frontmatter marks the skill internal: `metadata.internal` is
     * `true` or `1`, the values skills.sh accepts. An internal skill is meant
     * for the donor's own contributors rather than for those who install it.
     *
     * @param array<non-empty-string, string>|null $frontmatter result of {@see self::read()}
     *
     * @psalm-pure
     */
    public static function marksInternal(?array $frontmatter): bool
    {
        $flag = $frontmatter['metadata.internal'] ?? null;

        return $flag !== null && \in_array(\strtolower($flag), ['true', '1'], true);
    }

    /**
     * @return array<non-empty-string, string>|null
     */
    public function read(Path $skillDir): ?array
    {
        $file = (string) $skillDir->join('SKILL.md');
        if (!\is_file($file)) {
            return null;
        }

        $handle = @\fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }
        $contents = \fread($handle, self::READ_CAP_BYTES);
        \fclose($handle);
        if ($contents === false || $contents === '') {
            return null;
        }

        return self::parse($contents);
    }

    /**
     * @return array<non-empty-string, string>|null
     *
     * @psalm-pure
     */
    private static function parse(string $text): ?array
    {
        // Strip a UTF-8 BOM if the file happens to start with one.
        $text = \preg_replace('/\\A\\xEF\\xBB\\xBF/', '', $text) ?? $text;

        // Frontmatter must begin at byte 0 with a bare `---` line.
        if (!\preg_match('/\\A---\\R(.*?)\\R---(\\R|$)/s', $text, $matches)) {
            return null;
        }

        $body = $matches[1];
        $lines = \preg_split('/\\R/', $body);
        if ($lines === false) {
            return null;
        }

        $out = [];
        // The top-level key whose value is an indented block mapping, and the
        // indentation of that block's keys once its first key is seen.
        $parent = null;
        $indent = null;
        foreach ($lines as $line) {
            if (!\preg_match('/^([ \\t]*)([A-Za-z_][A-Za-z0-9_-]*)[ \\t]*:[ \\t]*(.*)$/', $line, $kv)) {
                // A blank line, a comment or an indented line, such as a list
                // item, leaves the block open; anything else at the top ends it.
                if (\trim($line) !== '' && \ltrim($line) === $line && !\str_starts_with($line, '#')) {
                    $parent = null;
                }
                continue;
            }
            $value = self::scalar($kv[3] ?? '');

            if ($kv[1] !== '') {
                // Below a scalar, such as the lines of a `description: |`, or
                // deeper than the block's first key: not a key of the block.
                if ($parent === null || ($indent ??= $kv[1]) !== $kv[1]) {
                    continue;
                }
                // YAML ends a key at a colon followed by a space: `internal:true`
                // is a scalar, however leniently a top-level `name:x` is read.
                if (\preg_match('/\\A[ \\t]*[A-Za-z_][A-Za-z0-9_-]*[ \\t]*:(?:[ \\t]|\\z)/', $line) !== 1) {
                    continue;
                }
                $out[$parent . '.' . $kv[2]] ??= $value;
                continue;
            }

            /** @var non-empty-string $key */
            $key = $kv[2];
            $parent = $value === '' ? $key : null;
            $indent = null;
            // A quoted `"{a: b}"` is a string, not a mapping.
            $flow = \str_starts_with(\ltrim($kv[3] ?? ''), '{') ? self::flowMapping($value) : [];
            foreach ($flow as $child => $childValue) {
                $out[$key . '.' . $child] ??= $childValue;
            }
            $out[$key] ??= $value;
        }

        return $out === [] ? null : $out;
    }

    /**
     * The keys of a one-line flow mapping such as `{internal: true, tier: "a"}`;
     * empty for any other value, including a flow mapping holding a collection.
     *
     * @return array<non-empty-string, string>
     *
     * @psalm-pure
     */
    private static function flowMapping(string $value): array
    {
        if (!\preg_match('/\\A\\{(.*)\\}\\z/', $value, $braces) || \trim($braces[1]) === '') {
            return [];
        }

        // A quoted key (`{"internal":true}`) ends at its quote; a plain one only
        // at a colon followed by a space, as `{internal:true}` holds one scalar.
        $pair = '/\\G[ \\t]*(?|"([A-Za-z_][A-Za-z0-9_-]*)"[ \\t]*:|\'([A-Za-z_][A-Za-z0-9_-]*)\'[ \\t]*:'
            . '|([A-Za-z_][A-Za-z0-9_-]*)[ \\t]*:(?=[ \\t,]|\\z))[ \\t]*'
            . '("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'|[^,"\'{}\\[\\]]*?)[ \\t]*(?:,|\\z)/';
        $body = $braces[1];
        $out = [];
        $offset = 0;
        while ($offset < \strlen($body)) {
            if (!\preg_match($pair, $body, $kv, 0, $offset)) {
                return [];
            }
            /** @var non-empty-string $child */
            $child = $kv[1];
            $out[$child] ??= self::scalar($kv[2]);
            $offset += \strlen($kv[0]);
        }

        return $out;
    }

    /**
     * A plain or quoted scalar without its trailing comment.
     *
     * @psalm-pure
     */
    private static function scalar(string $value): string
    {
        $value = \trim($value);
        // A quoted value ends at its closing quote, past `\"` or `''` escapes.
        if (\preg_match('/\\A(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\']|\'\')*)\')(?:[ \\t]+#.*)?\\z/', $value, $quoted)) {
            return ($quoted[2] ?? '') !== '' ? $quoted[2] : $quoted[1];
        }

        // A YAML comment starts at a `#` preceded by whitespace.
        return \rtrim(\preg_replace('/(?:\\A|[ \\t]+)#.*\\z/', '', $value) ?? $value);
    }
}
