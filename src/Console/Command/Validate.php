<?php

declare(strict_types=1);

namespace LLM\Skills\Console\Command;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\NullIO;
use Internal\Path;
use LLM\Skills\Console\ValidateCliDefinition;
use LLM\Skills\Validation\ValidateProjectRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Standalone `validate` for the `bin/skills` binary. Loads Composer to read the installed
 * packages when the working directory has a `composer.json`; without one, or when loading
 * fails, the checks that need no package still run, and the composer.json check reports why.
 *
 * @internal
 */
final class Validate extends Command
{
    #[\Override]
    protected function configure(): void
    {
        ValidateCliDefinition::apply($this, 'validate');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = \getcwd() ?: '.';

        return (new ValidateProjectRunner())->run(
            Path::create($cwd),
            self::tryBootstrapComposer($cwd),
            ValidateCliDefinition::fetch($input),
            ValidateCliDefinition::json($input),
            $output,
        );
    }

    private static function tryBootstrapComposer(string $cwd): ?Composer
    {
        if (!\is_file($cwd . '/composer.json')) {
            return null;
        }

        try {
            return Factory::create(new NullIO(), null, disablePlugins: true, disableScripts: true);
        } catch (\Throwable) {
            return null;
        }
    }
}
