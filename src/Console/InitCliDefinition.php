<?php

declare(strict_types=1);

namespace LLM\Skills\Console;

use LLM\Skills\Config\InitOptions;
use LLM\Skills\Config\InitPresets;
use LLM\Skills\Config\ProjectConfig;
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
    private const DESCRIPTION = 'Bootstrap a skills.json file, sync, and (when applicable) migrate '
        . 'project keys out of composer.json';

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
                'quick',
                null,
                InputOption::VALUE_NONE,
                'Answer every question from the detected project layout and ask for a '
                . 'single confirmation before writing.',
            )
            ->addOption(
                'no-sync',
                null,
                InputOption::VALUE_NONE,
                'Skip the automatic sync after the config is written. Only skills.json '
                . 'is created.',
            )
            ->addOption(
                'target',
                null,
                InputOption::VALUE_REQUIRED,
                'Destination directory for synced skills, relative to the project root. '
                . 'Pre-fills the wizard prompt; written as-is with --quick.',
            )
            ->addOption(
                'alias',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Path mirrored at the target via a junction/symlink. Repeatable. '
                . 'Passing it at all replaces the detected alias list.',
            )
            ->addOption(
                'trust',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Package pattern allowed to ship skills (e.g. acme/pkg, acme/*). '
                . 'Repeatable. Lands under dependencies.composer.trusted.',
            )
            ->addOption(
                'auto-sync',
                null,
                InputOption::VALUE_NONE,
                'Write auto-sync: true — run skills:update after every composer '
                . 'install/update. This is the default, so the flag only matters '
                . 'over a config that turned it off.',
            )
            ->addOption(
                'no-auto-sync',
                null,
                InputOption::VALUE_NONE,
                'Write auto-sync: false. Unrelated to --no-sync, which only skips the '
                . 'one sync this command would run.',
            )
            ->addOption(
                'discovery',
                null,
                InputOption::VALUE_NONE,
                'Write discovery: true — consider packages that ship skills without '
                . 'declaring extra.skills. This is the default.',
            )
            ->addOption(
                'no-discovery',
                null,
                InputOption::VALUE_NONE,
                'Write discovery: false — sync only what packages declare explicitly.',
            )
            ->addOption(
                'clean',
                null,
                InputOption::VALUE_NONE,
                'Write clean: true — every sync deletes the installed skills before copying, '
                . 'hand-written ones in the target included. Without either flag, init '
                . 'proposes it only for a target that holds no skills yet.',
            )
            ->addOption(
                'no-clean',
                null,
                InputOption::VALUE_NONE,
                'Leave clean off, so syncs merge into the installed skills.',
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
            quick: (bool) $input->getOption('quick'),
            sync: !(bool) $input->getOption('no-sync'),
            presets: self::buildPresets($input),
        );
    }

    /**
     * @throws \InvalidArgumentException when a preset is malformed or contradictory
     */
    private static function buildPresets(InputInterface $input): InitPresets
    {
        $target = self::optionalNonEmptyOption($input, 'target');
        $aliases = self::stringList($input, 'alias');

        // An alias that equals the target would have sync link a directory
        // onto itself, and the mapper refuses to load the result. Caught
        // here so the user learns it before a file is written.
        $targetNorm = self::normalisePath($target ?? ProjectConfig::DEFAULT_TARGET);
        foreach ($aliases ?? [] as $alias) {
            if (self::normalisePath($alias) === $targetNorm) {
                throw new \InvalidArgumentException(\sprintf(
                    '--alias=%s is the target directory; an alias must point at it, not be it',
                    $alias,
                ));
            }
        }

        return new InitPresets(
            target: $target,
            aliases: $aliases,
            trusted: self::stringList($input, 'trust'),
            autoSync: FlagPair::exclusive($input, 'auto-sync', 'no-auto-sync'),
            discovery: FlagPair::exclusive($input, 'discovery', 'no-discovery'),
            clean: FlagPair::exclusive($input, 'clean', 'no-clean'),
        );
    }

    /**
     * A repeatable option as a list, or `null` when it was never passed —
     * "not given" and "given as empty" are different answers, and only the
     * first should defer to the detected layout.
     *
     * @param non-empty-string $name
     *
     * @return list<non-empty-string>|null
     *
     * @throws \InvalidArgumentException when an occurrence is empty
     */
    private static function stringList(InputInterface $input, string $name): ?array
    {
        /** @var mixed $raw */
        $raw = $input->getOption($name);
        if (!\is_array($raw) || $raw === []) {
            return null;
        }

        $out = [];
        /** @var mixed $value */
        foreach ($raw as $value) {
            if (!\is_string($value) || \trim($value) === '') {
                throw new \InvalidArgumentException(\sprintf('--%s must be a non-empty string', $name));
            }
            /** @var non-empty-string $trimmed */
            $trimmed = \trim($value);
            $out[] = $trimmed;
        }

        return $out;
    }

    /**
     * @param non-empty-string $name
     *
     * @return non-empty-string|null
     *
     * @throws \InvalidArgumentException when the option is present but empty
     */
    private static function optionalNonEmptyOption(InputInterface $input, string $name): ?string
    {
        /** @var mixed $raw */
        $raw = $input->getOption($name);
        if ($raw === null) {
            return null;
        }
        if (!\is_string($raw) || \trim($raw) === '') {
            throw new \InvalidArgumentException(\sprintf('--%s must be a non-empty string', $name));
        }

        /** @var non-empty-string */
        return \trim($raw);
    }

    /**
     * Cheap lexical normalisation for same-path detection: forward slashes,
     * no trailing separator. Not a path resolver — that is the planner's job.
     *
     * @psalm-pure
     */
    private static function normalisePath(string $path): string
    {
        return \rtrim(\str_replace('\\', '/', $path), '/');
    }
}
