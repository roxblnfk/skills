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
 * `# comment`. Multi-line scalars, sequences and anchors are out of scope,
 * and nested mappings are skipped; the first occurrence of a key wins.
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
        foreach ($lines as $line) {
            // Indented lines belong to a nested mapping, such as a `name` under
            // `metadata:`, and must not override the skill's own keys.
            if (!\preg_match('/^([A-Za-z_][A-Za-z0-9_-]*)[ \\t]*:[ \\t]*(.*)$/', $line, $kv)) {
                continue;
            }
            /** @var non-empty-string $key */
            $key = $kv[1];
            if (isset($out[$key])) {
                continue;
            }
            $value = \rtrim($kv[2]);
            // A quoted value ends at its closing quote, past `\"` or `''` escapes.
            if (\preg_match('/\\A(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\']|\'\')*)\')(?:[ \\t]+#.*)?\\z/', $value, $quoted)) {
                $value = ($quoted[2] ?? '') !== '' ? $quoted[2] : $quoted[1];
            } else {
                // A YAML comment starts at a `#` preceded by whitespace.
                $value = \rtrim(\preg_replace('/(?:\\A|[ \\t]+)#.*\\z/', '', $value) ?? $value);
            }
            $out[$key] = $value;
        }

        return $out === [] ? null : $out;
    }
}
