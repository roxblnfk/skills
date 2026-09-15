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
     * @param bool $quick skip the per-question wizard and answer every knob from the
     *        detected project layout, then ask for a single confirmation before
     *        writing. Behaves like `--no-interaction` when there is no TTY to confirm
     *        on
     * @param bool $sync when true (default), the entrypoint syncs right after writing the
     *        config, so the project ends up with skills rather than with a config file and
     *        a second command to remember
     * @param InitPresets $presets config values given on the command line; they pre-fill the
     *        wizard's prompts and are written as-is when nothing prompts
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $path = 'skills.json',
        public bool $force = false,
        public bool $quick = false,
        public bool $sync = true,
        public InitPresets $presets = new InitPresets(),
    ) {}

    /**
     * @psalm-pure
     */
    public static function default(): self
    {
        return new self();
    }
}
