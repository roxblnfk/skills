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
     * @param non-empty-string $path destination for the external config. By default it must be a
     *        relative path inside the project root; with `$externalTarget` it may also be absolute
     *        or escape the project root. Validated downstream by the runner.
     * @param bool $force when true, overwrite an existing file at `$path` and an
     *        already-rewritten `extra.skills`
     * @param bool $externalTarget when true, `$path` may resolve outside the project root,
     *        mirroring the `external-target` project config key for the generated `target`
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $path = 'skills.json',
        public bool $force = false,
        public bool $externalTarget = false,
    ) {}

    /**
     * @psalm-pure
     */
    public static function default(): self
    {
        return new self();
    }
}
