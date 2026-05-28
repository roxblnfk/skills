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
use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Discovery\Provider\DonorProviderBuilder;
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
     * Auto-sync hook. Runs `skills:update` with default options on
     * every `composer install` / `update` unless the project opts
     * out via `auto-sync: false` (in `skills.json` or, for projects
     * still on the inline config, in `extra.skills`). The default
     * was flipped to on once it became clear most projects wanted
     * it; opt-outs are now the exception.
     *
     * Any failure is surfaced through the {@see IOInterface} but
     * never thrown — a broken sync must not abort the surrounding
     * `composer install` / `update`.
     *
     * The two events are handled with slightly different policy:
     *
     * - **`post-install-cmd`** is a fetch-only step from the user's
     *   point of view; an unexpected `composer.json` rewrite during
     *   `composer install` would be a surprise. The hook therefore
     *   passes `autoMigrate: false` so any legacy inline
     *   `extra.skills` block is read but not relocated.
     * - **`post-update-cmd`** runs when the user is actively
     *   reshuffling dependencies. A `composer.json` write is in
     *   character with that command, so the migration goes through.
     *
     * Either way, the explicit `composer skills:update` is the
     * unambiguous trigger for the migration; the auto-sync hook
     * just opportunistically takes the same code path on `update`.
     */
    public function onPostInstallOrUpdate(ScriptEvent $event): void
    {
        if ($this->composer === null || $this->io === null) {
            return;
        }

        $projectRoot = Path::create(\getcwd() ?: '.');
        try {
            $resolution = (new ProjectConfigMapper())->forProject(
                $projectRoot,
                $this->composer->getPackage()->getExtra(),
            );
        } catch (MalformedProjectConfig $e) {
            $this->io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return;
        }

        if (!$resolution->config->autoSync) {
            return;
        }

        // Banner so the user sees that the `llm/skills` plugin is doing
        // work after `composer install` / `composer update`. Without this,
        // the auto-sync output (which includes `[copy]` rows and skip
        // diagnostics) looks like noise from Composer itself.
        $this->io->write('<info>[llm/skills] running auto-sync after composer ' .
            ($event->getName() === ScriptEvents::POST_UPDATE_CMD ? 'update' : 'install') . '…</info>');

        $autoMigrate = $event->getName() === ScriptEvents::POST_UPDATE_CMD;
        $options = new SyncOptions(
            packageFilters: [],
            extraTrusted: [],
            targetOverride: null,
            interactive: false,
            dryRun: false,
            discovery: null,
            aliasOverrides: null,
            autoMigrate: $autoMigrate,
        );

        $extra = $this->composer->getPackage()->getExtra();
        $provider = (new DonorProviderBuilder())->build($projectRoot, $this->composer, $extra);
        (new SyncRunner())->run(
            $projectRoot,
            $provider,
            $extra,
            $this->io,
            $options,
        );
    }
}
