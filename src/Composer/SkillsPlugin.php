<?php

declare(strict_types=1);

namespace LLM\Skills\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event as ScriptEvent;
use Composer\Script\ScriptEvents;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Sync\SyncRunner;

/**
 * Composer plugin entrypoint for `llm/skills`.
 *
 * Wires our {@see CommandProvider} into Composer so that
 * `composer skills:update` becomes available.
 *
 * By default the plugin follows a **pull model**: nothing touches the user's
 * filesystem until they explicitly invoke `skills:update`. Projects that want
 * a hands-off setup can opt in by setting `extra.skills.auto-sync: true` —
 * the plugin then subscribes to {@see ScriptEvents::POST_INSTALL_CMD} and
 * {@see ScriptEvents::POST_UPDATE_CMD} and runs sync automatically. The
 * `composer --no-scripts` flag still suppresses the auto-run, because
 * Composer skips dispatching script events entirely in that mode.
 *
 * `activate()` captures the {@see Composer} and {@see IOInterface} handed in
 * by the host so the event callbacks can reuse them; `deactivate()` and
 * `uninstall()` are no-ops. Notably `uninstall()` does NOT wipe the synced
 * directory: files in there came from other vendor packages that are still
 * installed, and the user may have edited some of them locally. We refuse to
 * silently delete artefacts the user might still want.
 *
 * @internal
 */
final class SkillsPlugin implements PluginInterface, Capable, EventSubscriberInterface
{
    private ?Composer $composer = null;
    private ?IOInterface $io = null;

    /**
     * @psalm-pure
     */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstallOrUpdate',
            ScriptEvents::POST_UPDATE_CMD => 'onPostInstallOrUpdate',
        ];
    }

    /**
     * @psalm-external-mutation-free
     */
    #[\Override]
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

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

    /**
     * Auto-sync hook. Runs `skills:update` with default options when the
     * project opts in via `extra.skills.auto-sync: true`. Any failure is
     * surfaced through the {@see IOInterface} but never thrown — a broken
     * sync must not abort the surrounding `composer install` / `update`.
     */
    public function onPostInstallOrUpdate(ScriptEvent $event): void
    {
        if ($this->composer === null || $this->io === null) {
            return;
        }

        try {
            $project = (new ProjectConfigMapper())->fromExtra($this->composer->getPackage()->getExtra());
        } catch (MalformedProjectConfig $e) {
            $this->io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return;
        }

        if (!$project->autoSync) {
            return;
        }

        (new SyncRunner())->run($this->composer, $this->io, SyncOptions::default());
    }
}
