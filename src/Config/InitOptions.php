<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

/**
 * Parameters for `skills:init`, built from the CLI surface.
 *
 * @psalm-immutable
 */
final readonly class InitOptions
{
    /**
     * @param non-empty-string $path destination for the external config, relative to
     *        the project root. Validated downstream by the runner (no `..`-escape,
     *        not absolute).
     * @param bool $force when true, overwrite an existing file at `$path` and an
     *        already-rewritten `extra.skills`
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $path = 'skills.json',
        public bool $force = false,
    ) {}

    /**
     * @psalm-pure
     */
    public static function default(): self
    {
        return new self();
    }
}
