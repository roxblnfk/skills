<?php

declare(strict_types=1);

namespace LLM\Skills;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use LLM\Skills\Composer\CommandProvider;

final class SkillsPlugin implements PluginInterface, Capable
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        // No event subscriptions: the plugin exposes a CLI command that the
        // consumer wires into their own `post-update-cmd` script if they want
        // automatic sync after `composer update`.
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }
}
