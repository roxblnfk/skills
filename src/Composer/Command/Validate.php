<?php

declare(strict_types=1);

namespace LLM\Skills\Composer\Command;

use Composer\Command\BaseCommand;
use Internal\Path;
use LLM\Skills\Console\ValidateCliDefinition;
use LLM\Skills\Validation\ValidateProjectRunner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Composer plugin entrypoint for `skills:validate`.
 * Registered by {@see \LLM\Skills\Composer\CommandProvider}.
 *
 * Runs in the project root Composer resolved. For the binary entrypoint see
 * {@see \LLM\Skills\Console\Command\Validate}.
 *
 * @internal
 */
final class Validate extends BaseCommand
{
    #[\Override]
    protected function configure(): void
    {
        ValidateCliDefinition::apply($this, 'skills:validate');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (new ValidateProjectRunner())->run(
            Path::create(\getcwd() ?: '.'),
            $this->requireComposer(),
            ValidateCliDefinition::fetch($input),
            ValidateCliDefinition::json($input),
            $output,
        );
    }
}
