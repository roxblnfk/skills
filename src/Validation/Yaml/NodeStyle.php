<?php

declare(strict_types=1);

namespace LLM\Skills\Validation\Yaml;

/**
 * How a node was written. The validator reports on the style as well as the value:
 * strict parsers reject flow collections and aliases, and an empty value differs from `""`.
 *
 * @internal
 */
enum NodeStyle
{
    case Block;
    case Flow;
    case Plain;
    case DoubleQuoted;
    case SingleQuoted;
    case Literal;
    case Folded;

    /**
     * No value at all, as in `key:` with nothing after it.
     */
    case Null;
    case Alias;
}
