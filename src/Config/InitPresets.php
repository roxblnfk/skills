<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

use LLM\Skills\Config\Mapper\ProjectConfigMapper;

/**
 * Config values handed to `skills:init` on the command line, so a setup
 * can be scripted in one invocation instead of typed into the wizard.
 *
 * A preset is a default, not a decree: in the interactive wizard it
 * arrives pre-filled at the prompt and the user can still change it. What
 * makes it final is `--quick` or `--no-interaction`, where no prompt
 * happens at all — which is the point of passing it in the first place.
 *
 * Every field is nullable, and `null` means "not given": the value then
 * comes from wherever it would have come without the flag — the existing
 * `skills.json`, the inline `extra.skills`, the detected layout, or the
 * built-in default.
 *
 * @psalm-immutable
 */
final readonly class InitPresets
{
    /**
     * @param non-empty-string|null $target `--target`
     * @param list<non-empty-string>|null $aliases `--alias`, repeatable. An empty list is
     *        distinct from `null`: it is a given answer that happens to be "no aliases"
     * @param list<non-empty-string>|null $trusted `--trust`, repeatable; lands under
     *        `dependencies.composer.trusted`
     * @param bool|null $autoSync `--auto-sync` / `--no-auto-sync`
     * @param bool|null $discovery `--discovery` / `--no-discovery`
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public ?string $target = null,
        public ?array $aliases = null,
        public ?array $trusted = null,
        public ?bool $autoSync = null,
        public ?bool $discovery = null,
    ) {}

    /**
     * @psalm-pure
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * @psalm-mutation-free
     */
    public function isEmpty(): bool
    {
        return $this->target === null
            && $this->aliases === null
            && $this->trusted === null
            && $this->autoSync === null
            && $this->discovery === null;
    }

    /**
     * Overlay the given values onto a project-keys map — the same shape
     * `skills.json` is written from. Called last, after the existing
     * config and the detected layout have had their say, so an explicit
     * flag wins over both.
     *
     * The trust list merges into the existing `dependencies.composer`
     * entry rather than replacing it: a sibling `enabled: false` or an
     * `npm` block is nobody's business here.
     *
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     *
     * @psalm-mutation-free
     */
    public function apply(array $defaults): array
    {
        if ($this->target !== null) {
            $defaults['target'] = $this->target;
        }
        if ($this->aliases !== null) {
            // An empty list is the default, and writing `"aliases": []`
            // into a fresh config would only be noise.
            if ($this->aliases === []) {
                unset($defaults['aliases']);
            } else {
                $defaults['aliases'] = $this->aliases;
            }
        }
        if ($this->autoSync !== null) {
            $defaults['auto-sync'] = $this->autoSync;
        }
        if ($this->discovery !== null) {
            $defaults['discovery'] = $this->discovery;
        }
        if ($this->trusted !== null) {
            /** @var array<string, mixed> $dependencies */
            $dependencies = \is_array($defaults[ProjectConfigMapper::DEPENDENCIES_KEY] ?? null)
                ? $defaults[ProjectConfigMapper::DEPENDENCIES_KEY]
                : [];
            /** @var array<string, mixed> $composer */
            $composer = \is_array($dependencies['composer'] ?? null) ? $dependencies['composer'] : [];
            if ($this->trusted === []) {
                unset($composer['trusted']);
            } else {
                $composer['trusted'] = $this->trusted;
            }

            if ($composer === []) {
                unset($dependencies['composer']);
            } else {
                $dependencies['composer'] = $composer;
            }

            if ($dependencies === []) {
                unset($defaults[ProjectConfigMapper::DEPENDENCIES_KEY]);
            } else {
                $defaults[ProjectConfigMapper::DEPENDENCIES_KEY] = $dependencies;
            }
        }

        return $defaults;
    }
}
