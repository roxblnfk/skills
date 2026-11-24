<?php

declare(strict_types=1);

namespace LLM\Skills\Validation\Yaml;

/**
 * A frontmatter YAML node with the file line it starts on. Every scalar stays a string,
 * as strictyaml reads it: `true` and `1.0` are not converted.
 *
 * @internal
 */
final class YamlNode
{
    /**
     * @param string $scalar the value of a scalar node; empty for collections
     * @param array<array-key, YamlNode> $items mapping entries by key, or list items; empty for scalars
     *
     * @psalm-mutation-free
     */
    private function __construct(
        public readonly NodeKind $kind,
        public readonly string $scalar,
        public readonly array $items,
        public readonly int $line,
        public readonly NodeStyle $style,
    ) {}

    /**
     * @param array<array-key, YamlNode> $entries
     *
     * @psalm-pure
     */
    public static function map(array $entries, int $line, NodeStyle $style): self
    {
        return new self(NodeKind::Map, '', $entries, $line, $style);
    }

    /**
     * @param list<YamlNode> $items
     *
     * @psalm-pure
     */
    public static function list(array $items, int $line, NodeStyle $style): self
    {
        return new self(NodeKind::List, '', $items, $line, $style);
    }

    /**
     * @psalm-pure
     */
    public static function scalar(string $value, int $line, NodeStyle $style): self
    {
        return new self(NodeKind::Scalar, $value, [], $line, $style);
    }

    /**
     * @psalm-pure
     */
    public static function null(int $line): self
    {
        return new self(NodeKind::Scalar, '', [], $line, NodeStyle::Null);
    }

    /**
     * @psalm-mutation-free
     */
    public function isNull(): bool
    {
        return $this->kind === NodeKind::Scalar && $this->style === NodeStyle::Null;
    }

    /**
     * Whether the node is a string with visible content.
     *
     * @psalm-mutation-free
     */
    public function isFilledScalar(): bool
    {
        return $this->kind === NodeKind::Scalar && !$this->isNull() && \trim($this->scalar) !== '';
    }

    /**
     * The node as plain PHP values: strings, arrays, and `null` for an empty value.
     *
     * @psalm-mutation-free
     */
    public function toPlain(): mixed
    {
        return match ($this->kind) {
            NodeKind::Scalar => $this->isNull() ? null : $this->scalar,
            default => \array_map(static fn(self $n): mixed => $n->toPlain(), $this->items),
        };
    }
}
