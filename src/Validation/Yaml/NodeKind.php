<?php

declare(strict_types=1);

namespace LLM\Skills\Validation\Yaml;

/**
 * @internal
 */
enum NodeKind
{
    case Map;
    case List;
    case Scalar;
}
