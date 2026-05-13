<?php

declare(strict_types=1);

namespace LLM\Skills\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

final class SkillsPlugin implements PluginInterface, Capable
{
    private const LOG_PREFIX = '<info>[llm/skills]</info>';

    #[\Override]
    public function activate(Composer $composer, IOInterface $io): void
    {
        // No event subscriptions: the plugin exposes a CLI command that the
        // consumer wires into their own `post-update-cmd` script if they want
        // automatic sync after `composer update`.
        $io->write(self::LOG_PREFIX . ' activate() called — plugin loaded');
    }

    #[\Override]
    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $io->write(self::LOG_PREFIX . ' deactivate() called');
    }

    #[\Override]
    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $io->write(self::LOG_PREFIX . ' uninstall() called');
    }

    #[\Override]
    public function getCapabilities(): array
    {
        // Cannot use $io here (Capable::getCapabilities has no IO argument),
        // so emit via error_log so it shows up even in non-verbose mode.
        \error_log('[llm/skills] getCapabilities() called — registering CommandProvider');

        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }
}
