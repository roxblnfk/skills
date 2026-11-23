<?php

declare(strict_types=1);

namespace LLM\Skills\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared CLI surface for `lint`, used by both the Composer plugin entrypoint
 * ({@see \LLM\Skills\Composer\Command\Lint}) and the standalone binary
 * ({@see \LLM\Skills\Console\Command\Lint}).
 */
final class LintCliDefinition
{
    private const DESCRIPTION = 'Validate skills against the Agent Skills spec and the rules llm/skills installs by';
    private const HELP = <<<'TXT'
        Checks each skill's SKILL.md and frontmatter against the Agent Skills specification,
        agent-specific extension keys, body size, links to bundled files, and how the
        llm/skills plugin reads the skill. Works on any skill, installed or not.

        A PATH is a skill directory, a SKILL.md file, or a directory to search for skills
        (vendor/, node_modules/ and .git/ are skipped). Without a PATH the working
        directory is searched.

        Output is one finding per line, "<LEVEL> <path>[:<line>]: <message>" with the level
        (ERROR, WARN or INFO) padded to five characters, then "N error(s), M warning(s)"; --json prints an array of {level,file,line,message,code}
        instead. Each message names its fix. Exits 1 when any finding is an ERROR.
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
            ->addArgument(
                'paths',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Skill directories, SKILL.md files, or directories to search for skills.',
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Print findings as a JSON array of {level, file, line, message, code}.',
            );
    }

    /**
     * @return list<string>
     */
    public static function paths(InputInterface $input): array
    {
        $paths = [];
        /** @var mixed $path */
        foreach ((array) $input->getArgument('paths') as $path) {
            \is_string($path) and $paths[] = $path;
        }

        return $paths;
    }

    public static function json(InputInterface $input): bool
    {
        return (bool) $input->getOption('json');
    }
}
