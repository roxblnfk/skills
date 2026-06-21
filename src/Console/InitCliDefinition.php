<?php

declare(strict_types=1);

namespace LLM\Skills\Console;

use LLM\Skills\Config\InitOptions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared CLI surface for `skills:init` — used by both the Composer plugin
 * entrypoint ({@see \LLM\Skills\Composer\Command\Init}) and the standalone
 * binary ({@see \LLM\Skills\Console\Command\Init}).
 *
 * Centralising `configure()` and `buildOptions()` here guarantees that
 * both entrypoints accept the same flags.
 */
final class InitCliDefinition
{
    private const DESCRIPTION = 'Bootstrap a skills.json file and (when applicable) migrate project '
        . 'keys out of composer.json';

    /**
     * @param non-empty-string $name
     * @param list<non-empty-string> $aliases
     */
    public static function apply(Command $command, string $name, array $aliases = []): void
    {
        $command
            ->setName($name)
            ->setAliases($aliases)
            ->setDescription(self::DESCRIPTION)
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Where to create the external config, relative to the project root. '
                . 'Default "skills.json". Must be inside the project root.',
                'skills.json',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite an existing file at the target path.',
            )
            ->addOption(
                'external-target',
                null,
                InputOption::VALUE_NONE,
                'Allow --path to resolve outside the project root (absolute or via "..").',
            );
    }

    /**
     * @throws \InvalidArgumentException when `--path` is empty
     */
    public static function buildOptions(InputInterface $input): InitOptions
    {
        /** @var mixed $rawPath */
        $rawPath = $input->getOption('path');
        if (!\is_string($rawPath) || $rawPath === '') {
            throw new \InvalidArgumentException('--path must be a non-empty string');
        }

        return new InitOptions(
            path: $rawPath,
            force: (bool) $input->getOption('force'),
            externalTarget: (bool) $input->getOption('external-target'),
        );
    }
}
