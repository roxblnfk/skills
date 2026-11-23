<?php

declare(strict_types=1);

namespace LLM\Skills\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared CLI surface for `validate`, used by both the Composer plugin entrypoint
 * ({@see \LLM\Skills\Composer\Command\Validate}) and the standalone binary
 * ({@see \LLM\Skills\Console\Command\Validate}).
 */
final class ValidateCliDefinition
{
    private const DESCRIPTION = 'Validate the project\'s skills configuration, shipped skills and the skills a sync installs';
    private const HELP = <<<'TXT'
        Checks the project in the working directory, for CI, without writing anything:

          - skills.json, skills.local.json and the inline extra.skills block, read as
            skills:update reads them, including the target and alias paths;
          - what composer.json declares for distribution: extra.skills.source and
            extra.skills.sources, and every skill in the declared source directories;
          - what skills:update would install from donors: installed packages and dir
            sources, plus remote sources with --fetch; two donors shipping one skill
            name fail the run, as they abort the sync;
          - every other skill in the target directory, such as one put there by hand.

        The content of a third-party donor's skills is reported as warnings at most: the
        project cannot fix it. Skills of a dir source are the project's own. Each skill directory is checked once, by the first of the checks above that
        reaches it.

        Output is one finding per line, "<LEVEL> <path>[:<line>]: <message>" with the level
        (ERROR, WARN or INFO) padded to five characters, then "N error(s), M warning(s)"; --json prints an array of {level,file,line,message,code}
        instead. Each message names its fix. Exits 1 when any finding is an ERROR; warnings
        do not fail the run.
        TXT;

    /**
     * @param non-empty-string $name
     */
    public static function apply(Command $command, string $name): void
    {
        $command
            ->setName($name)
            ->setDescription(self::DESCRIPTION)
            ->setHelp(self::HELP)
            ->addOption(
                'fetch',
                null,
                InputOption::VALUE_NONE,
                'Also fetch the donors that need the network: remote sources[] entries and the sources installed packages declare.',
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Print findings as a JSON array of {level, file, line, message, code}.',
            );
    }

    public static function fetch(InputInterface $input): bool
    {
        return (bool) $input->getOption('fetch');
    }

    public static function json(InputInterface $input): bool
    {
        return (bool) $input->getOption('json');
    }
}
