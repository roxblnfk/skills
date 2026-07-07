<?php

declare(strict_types=1);

namespace LLM\Skills\Composer;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use LLM\Skills\Composer\Command\Add;
use LLM\Skills\Composer\Command\Init;
use LLM\Skills\Composer\Command\Show;
use LLM\Skills\Composer\Command\Sync;

/**
 * Hooks the plugin's CLI commands into Composer.
 *
 * Four commands ship today:
 *
 * - {@see Sync}  — `skills:update`, the primary "copy skills into the project" command.
 * - {@see Show}  — `skills:show`, the read-only inspection counterpart.
 * - {@see Init}  — `skills:init`, bootstraps `skills.json` (and migrates legacy inline keys).
 * - {@see Add}   — `skills:add`, registers a donor source (currently GitHub) and fetches
 *                  its skills immediately. The standalone `bin/skills` binary mirrors all
 *                  four under short names (`update` / `show` / `init` / `add`).
 *
 * @internal
 */
final class CommandProvider implements CommandProviderCapability
{
    /**
     * @return list<\Composer\Command\BaseCommand>
     */
    #[\Override]
    public function getCommands(): array
    {
        return [
            new Sync(),
            new Show(),
            new Init(),
            new Add(),
        ];
    }
}
