<?php

declare(strict_types=1);

namespace LLM\Skills\Composer;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use LLM\Skills\Composer\Command\Show;
use LLM\Skills\Composer\Command\Sync;

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
        ];
    }
}
