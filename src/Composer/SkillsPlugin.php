<?php

declare(strict_types=1);

namespace LLM\Skills\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

/**
 * Composer plugin entrypoint for `llm/skills`.
 *
 * Wires our {@see CommandProvider} into Composer so that
 * `composer skills:update` becomes available. We intentionally subscribe to
 * **no** Composer events — `llm/skills` follows a pull model: nothing
 * touches the user's filesystem until they explicitly invoke
 * `skills:update`. Projects that want post-update auto-sync wire it up in
 * their own `scripts.post-update-cmd` (see README).
 *
 * `activate()` / `deactivate()` / `uninstall()` are required by
 * {@see PluginInterface}; the plugin is stateless, so they are no-ops.
 * Notably `uninstall()` does NOT wipe the synced directory: files in there
 * came from other vendor packages that are still installed, and the user
 * may have edited some of them locally. We refuse to silently delete
 * artefacts the user might still want.
 *
 * @internal
 */
final class SkillsPlugin implements PluginInterface, Capable
{
    /**
     * @psalm-mutation-free
     */
    #[\Override]
    public function activate(Composer $composer, IOInterface $io): void {}

    /**
     * @psalm-mutation-free
     */
    #[\Override]
    public function deactivate(Composer $composer, IOInterface $io): void {}

    /**
     * @psalm-mutation-free
     */
    #[\Override]
    public function uninstall(Composer $composer, IOInterface $io): void {}

    /**
     * @psalm-pure
     */
    #[\Override]
    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }
}
