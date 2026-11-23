<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

use LLM\Skills\Validation\Yaml\FrontmatterParser;
use LLM\Skills\Validation\Yaml\YamlNode;
use LLM\Skills\Validation\Yaml\YamlSyntaxError;

/**
 * A parsed `SKILL.md`: the frontmatter node tree, the body and where each starts.
 *
 * @internal
 */
final class SkillDocument
{
    public const FRONTMATTER_MISSING = 'frontmatter-missing';
    public const FRONTMATTER_UNCLOSED = 'frontmatter-unclosed';

    public ?YamlNode $frontmatter = null;
    public ?string $frontmatterError = null;
    public ?int $frontmatterErrorLine = null;
    public string $body = '';

    /** File line number of the first body line. */
    public int $bodyStart = 1;

    public bool $bom = false;

    /**
     * Byte offset just past the closing delimiter, before its line break; `null` without
     * frontmatter. llm/skills sees the frontmatter when this is within its 4 KiB read.
     */
    public ?int $frontmatterEnd = null;

    /** @var list<ParseNote> */
    public array $notes = [];

    /**
     * `null` when the document has a closed frontmatter block, else
     * {@see self::FRONTMATTER_MISSING} or {@see self::FRONTMATTER_UNCLOSED}.
     */
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
            $doc->missing = self::FRONTMATTER_MISSING;
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
            $doc->missing = self::FRONTMATTER_UNCLOSED;

            return $doc;
        }
        \preg_match_all('/\r\n|\n|\r/', $content, $breaks, \PREG_OFFSET_CAPTURE);
        $break = $breaks[0][$close] ?? null;
        $doc->frontmatterEnd = ($break !== null ? $break[1] : \strlen($content)) + ($doc->bom ? 3 : 0);
        foreach ([0, $close] as $i) {
            if ($lines[$i] !== '---') {
                $doc->notes[] = new ParseNote(
                    $i + 1,
                    'delimiter-trailing-space',
                    '"---" delimiter has trailing whitespace, so llm/skills does not see the frontmatter '
                    . '(name, metadata.internal); delete the trailing spaces',
                );
            }
        }
        $doc->body = \implode("\n", \array_slice($lines, $close + 1));
        $doc->bodyStart = $close + 2;
        $parser = new FrontmatterParser(\implode("\n", \array_slice($lines, 1, $close - 1)), 2);
        try {
            $doc->frontmatter = $parser->parseDocument();
        } catch (YamlSyntaxError $e) {
            $doc->frontmatterError = $e->getMessage();
            $doc->frontmatterErrorLine = $e->yamlLine;
        }
        $doc->notes = [...$doc->notes, ...$parser->notes()];

        return $doc;
    }
}
