<?php

declare(strict_types=1);

namespace LLM\Skills\Validation\Yaml;

/**
 * Frontmatter the parser cannot read. The message names the fix.
 *
 * @internal
 */
final class YamlSyntaxError extends \RuntimeException
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        string $message,
        public readonly int $yamlLine,
    ) {
        parent::__construct($message);
    }
}
