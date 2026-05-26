<?php

declare(strict_types=1);

namespace LLM\Skills\Console;

use LLM\Skills\Config\AddOptions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared CLI surface for `skills:add` — used by both the Composer
 * plugin entrypoint ({@see \LLM\Skills\Composer\Command\Add}) and the
 * standalone binary ({@see \LLM\Skills\Console\Command\Add}).
 *
 * Centralising `configure()` and `buildOptions()` here guarantees that
 * both entrypoints accept the same flags and produce the same
 * {@see AddOptions} value object.
 */
final class AddCliDefinition
{
    private const DESCRIPTION = 'Register a remote donor in skills.json and fetch its skills.';

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
            ->addArgument(
                'input',
                InputArgument::REQUIRED,
                'Adapter-specific identifier: shorthand (owner/repo), a full URL, '
                . 'or shorthand@ref. The adapter (selected via --from or inferred '
                . 'from a URL) decides how to interpret it.',
            )
            ->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Adapter id (github, gitlab, …). Required for shorthand input '
                . '(which has no host to infer from). When omitted with a full URL, '
                . 'the adapter is selected from the URL\'s host.',
            )
            ->addOption(
                'host',
                null,
                InputOption::VALUE_REQUIRED,
                'Override the adapter\'s default host. Use this for GitHub Enterprise, '
                . 'self-hosted GitLab, or a private Packagist.',
            )
            ->addOption(
                'ref',
                null,
                InputOption::VALUE_REQUIRED,
                'Pin a specific tag, branch, SHA, or Composer-style constraint. '
                . 'Without this flag, the adapter resolves the latest stable tag, '
                . 'falling back to the default branch HEAD if no stable tag exists.',
            )
            ->addOption(
                'no-sync',
                null,
                InputOption::VALUE_NONE,
                'Skip the automatic single-entry sync after the add. Only the '
                . 'skills.json manifest is updated.',
            );
    }

    /**
     * @throws \InvalidArgumentException when the CLI shape is malformed
     */
    public static function buildOptions(InputInterface $input): AddOptions
    {
        $inputArg = self::requireNonEmptyArg($input, 'input');
        $from = self::optionalNonEmptyOption($input, 'from');
        $host = self::optionalNonEmptyOption($input, 'host');
        $ref = self::optionalNonEmptyOption($input, 'ref');
        $noSync = (bool) $input->getOption('no-sync');

        return new AddOptions(
            input: $inputArg,
            from: $from,
            host: $host,
            ref: $ref,
            sync: !$noSync,
        );
    }

    /**
     * @return non-empty-string
     */
    private static function requireNonEmptyArg(InputInterface $input, string $name): string
    {
        /** @var mixed $value */
        $value = $input->getArgument($name);
        if (!\is_string($value) || $value === '') {
            throw new \InvalidArgumentException($name . ' must be a non-empty string');
        }
        return $value;
    }

    /**
     * @return non-empty-string|null
     */
    private static function optionalNonEmptyOption(InputInterface $input, string $name): ?string
    {
        /** @var mixed $value */
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }
        if (!\is_string($value) || $value === '') {
            throw new \InvalidArgumentException('--' . $name . ' must be a non-empty string');
        }
        return $value;
    }
}
