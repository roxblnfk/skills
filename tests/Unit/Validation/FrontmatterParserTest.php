<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Validation;

use LLM\Skills\Validation\Yaml\FrontmatterParser;
use LLM\Skills\Validation\Yaml\NodeKind;
use LLM\Skills\Validation\Yaml\NodeStyle;
use LLM\Skills\Validation\Yaml\YamlNode;
use LLM\Skills\Validation\Yaml\YamlSyntaxError;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(FrontmatterParser::class)]
#[Covers(YamlNode::class)]
final class FrontmatterParserTest
{
    #[DataSet(["k: plain text", 'plain text'], 'plain')]
    #[DataSet(["k: true", 'true'], 'boolean-looking stays a string')]
    #[DataSet(["k: 1.0", '1.0'], 'number-looking stays a string')]
    #[DataSet(["k: text # comment", 'text'], 'trailing comment')]
    #[DataSet(["k: a#b", 'a#b'], 'hash inside a word')]
    #[DataSet(["k: first\n  second", 'first second'], 'continued plain')]
    #[DataSet(["k: first\n\n  second", "first\nsecond"], 'blank line in a plain value')]
    #[DataSet(["k:\n  on the next line", 'on the next line'], 'value on the next line')]
    #[DataSet(["k: \"a\\tb \\u00e9 \\x41\"", "a\tb é A"], 'double-quoted escapes')]
    #[DataSet(["k: 'it''s'", "it's"], 'single-quoted doubled quote')]
    #[DataSet(["k: \"one\n  two\"", 'one two'], 'multi-line double-quoted')]
    #[DataSet(["k: \"C:\\\\\n  temp\"", 'C:\\ temp'], 'escaped backslash ending a line')]
    #[DataSet(["k: \"a\\\n  b\"", 'ab'], 'escaped line break')]
    #[DataSet(["k: \"a\\\\\\\n  b\"", 'a\\b'], 'escaped backslash then escaped line break')]
    #[DataSet(["k: \"foo \\\n  bar\"", 'foo bar'], 'blank kept before an escaped line break')]
    #[DataSet(["k: \"a   \n  b\"", 'a b'], 'trailing blanks dropped at a plain line break')]
    #[DataSet(["\"quoted key\": v", 'v', 'quoted key'], 'quoted key')]
    #[DataSet(["k: |\n  a\n  b\n", "a\nb\n"], 'literal clip')]
    #[DataSet(["k: |-\n  a\n  b", "a\nb"], 'literal strip')]
    #[DataSet(["k: |+\n  a\n", "a\n\n"], 'literal keep')]
    #[DataSet(["k: >\n  a\n  b\n\n  c", "a b\nc\n"], 'folded')]
    #[DataSet(["k: >2\n   indented\n  a", " indented\na\n"], 'folded with explicit indent')]
    public function readsScalars(string $yaml, string $expected, string $key = 'k'): void
    {
        $root = (new FrontmatterParser($yaml, 1))->parseDocument();

        Assert::same($root->kind, NodeKind::Map);
        Assert::same($root->items[$key]->scalar, $expected);
    }

    public function readsNestedBlocks(): void
    {
        $yaml = "metadata:\n  internal: true\n  tags:\n    - a\n    - b: c\n      d: e\nlist:\n- x\n-\n";

        $plain = (new FrontmatterParser($yaml, 1))->parseDocument()->toPlain();

        Assert::same($plain, [
            'metadata' => ['internal' => 'true', 'tags' => ['a', ['b' => 'c', 'd' => 'e']]],
            'list' => ['x', null],
        ]);
    }

    public function readsFlowCollectionsAndNotesThem(): void
    {
        $parser = new FrontmatterParser("k: {a: [1, 'two'], b: }\nl: [x,\n  y]", 5);

        $plain = $parser->parseDocument()->toPlain();

        Assert::same($plain, ['k' => ['a' => ['1', 'two'], 'b' => null], 'l' => ['x', 'y']]);
        Assert::same(\array_map(static fn($n): array => [$n->line, $n->code], $parser->notes()), [
            [5, 'yaml-flow-style'],
            [6, 'yaml-flow-style'],
        ]);
    }

    #[DataSet(["tags: [it's, fine]", ["it's", 'fine']], 'apostrophe inside a plain item')]
    #[DataSet(["tags: [a, # first\n  b] # after", ['a', 'b']], 'comment inside a multi-line collection')]
    #[DataSet(["tags: ['a # b',\n  c]", ['a # b', 'c']], 'hash inside a quoted item')]
    public function readsFlowCollectionsWrittenLoosely(string $yaml, array $expected): void
    {
        $plain = (new FrontmatterParser($yaml, 1))->parseDocument()->toPlain();

        Assert::same($plain, ['tags' => $expected]);
    }

    public function notesAnchorsAliasesAndTags(): void
    {
        $parser = new FrontmatterParser("a: &x value\nb: *x\nc: !tag text", 1);

        $root = $parser->parseDocument();

        Assert::same($root->items['a']->scalar, 'value');
        Assert::same($root->items['b']->style, NodeStyle::Alias);
        Assert::same($root->items['c']->scalar, 'text');
        Assert::count($parser->notes(), 3);
    }

    public function readsALongRunOfAnchorsWithoutRecursing(): void
    {
        $parser = new FrontmatterParser('k: ' . \str_repeat('&a ', 100_000) . 'value', 1);

        $root = $parser->parseDocument();

        Assert::same($root->items['k']->scalar, 'value');
        Assert::count($parser->notes(), 1);
    }

    public function recordsTheFileLineOfEachNode(): void
    {
        $root = (new FrontmatterParser("# comment\nname: x\n\ndescription: y", 2))->parseDocument();

        Assert::same($root->items['name']->line, 3);
        Assert::same($root->items['description']->line, 5);
    }

    public function anEmptyValueIsNull(): void
    {
        $root = (new FrontmatterParser("k:\nq: \"\"", 1))->parseDocument();

        Assert::true($root->items['k']->isNull());
        Assert::false($root->items['q']->isNull());
        Assert::false($root->items['q']->isFilledScalar());
    }

    public function anEmptyDocumentIsAnEmptyMap(): void
    {
        $root = (new FrontmatterParser('', 2))->parseDocument();

        Assert::same($root->kind, NodeKind::Map);
        Assert::same($root->items, []);
    }

    #[DataSet(["a: 1\na: 2", 2, 'duplicate key "a"'], 'duplicate key')]
    #[DataSet(["m: {a: 1, a: 2}", 1, 'duplicate key "a"'], 'duplicate flow key')]
    #[DataSet(["tags: [a'b,'c]", 1, 'unterminated'], 'unterminated quote in a flow item')]
    #[DataSet(["a: x: y", 1, 'wrap the whole value in quotes'], 'colon in a plain value')]
    #[DataSet(["a:b", 1, 'add a space after the colon'], 'missing space after colon')]
    #[DataSet(["a:\n    b: 1\n  c: 2", 3, 'unexpected indentation'], 'over-indented sibling')]
    #[DataSet(["a:\n\t- x", 2, 'tab character'], 'tab indentation')]
    #[DataSet(["a: \"open", 1, 'unterminated quoted string'], 'unterminated quote')]
    #[DataSet(["a: \"x\" tail", 1, 'after the closing quote'], 'text after a quote')]
    #[DataSet(["a: \"\\q\"", 1, 'invalid escape'], 'bad escape')]
    #[DataSet(["a: @x", 1, 'cannot start with "@"'], 'reserved indicator')]
    #[DataSet(["a: - x", 1, 'its own line'], 'inline list')]
    #[DataSet(["a: [x", 1, 'unterminated flow collection'], 'unterminated flow')]
    #[DataSet(["a: |x", 1, 'invalid block scalar header'], 'bad block header')]
    #[DataSet(["- x\nb: y", 2, 'unexpected content'], 'map after a top-level list')]
    public function rejectsInvalidSyntax(string $yaml, int $line, string $message): void
    {
        try {
            (new FrontmatterParser($yaml, 1))->parseDocument();
        } catch (YamlSyntaxError $e) {
            Assert::same($e->yamlLine, $line);
            Assert::string($e->getMessage())->contains($message);

            return;
        }

        Assert::fail('expected a syntax error');
    }

    public function rejectsHostileNesting(): void
    {
        $yaml = 'a: ' . \str_repeat('[', 100) . \str_repeat(']', 100);

        try {
            (new FrontmatterParser($yaml, 1))->parseDocument();
        } catch (YamlSyntaxError $e) {
            Assert::string($e->getMessage())->contains('nesting deeper than 64 levels');

            return;
        }

        Assert::fail('expected a syntax error');
    }
}
