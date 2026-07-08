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
    private const DESCRIPTION = 'Register a donor source in skills.json and fetch its skills.';

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
                . 'shorthand@ref, or a local directory path (./skills, ../shared, '
                . 'an absolute path). The adapter (selected via --from, inferred '
                . 'from a URL, or inferred from a path prefix) decides how to '
                . 'interpret it.',
            )
            ->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Adapter id (github, gitlab, dir, …). Defaults to "github" for '
                . 'shorthand input. A path-shaped input (./skills, ../shared, an '
                . 'absolute path) selects "dir" automatically. When a full URL is '
                . 'passed, the adapter is selected from the URL\'s host (and '
                . '`--from` is only needed to override).',
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
                'skill',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Restrict the donor to selected skill directory names. Repeatable. '
                . 'Each value is appended to the entry\'s allowlist (the field is '
                . 'additive across consecutive `skills:add` calls). Without this '
                . 'flag, every skill the donor ships is synced.',
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
        $skills = self::collectSkillNames($input);
        $noSync = (bool) $input->getOption('no-sync');

        return new AddOptions(
            input: $inputArg,
            from: $from,
            host: $host,
            ref: $ref,
            sync: !$noSync,
            skills: $skills,
        );
    }

    /**
     * Collect `--skill` values into a list of non-empty strings, or
     * return `null` when the flag was not used. Empty values and
     * duplicates are rejected eagerly — the writer can dedupe on
     * merge, but typo-induced empties at the CLI are almost always
     * a user mistake worth surfacing now.
     *
     * @return list<non-empty-string>|null
     *
     * @throws \InvalidArgumentException when any value is empty or
     *         when the same name was passed twice
     */
    private static function collectSkillNames(InputInterface $input): ?array
    {
        /** @var mixed $raw */
        $raw = $input->getOption('skill');
        if (!\is_array($raw) || $raw === []) {
            return null;
        }

        /** @var list<non-empty-string> $out */
        $out = [];
        $seen = [];
        /** @var mixed $value */
        foreach ($raw as $value) {
            if (!\is_string($value) || $value === '') {
                throw new \InvalidArgumentException(
                    '--skill must be a non-empty string',
                );
            }
            if (isset($seen[$value])) {
                throw new \InvalidArgumentException(
                    '--skill "' . $value . '" was passed more than once',
                );
            }
            $seen[$value] = true;
            $out[] = $value;
        }
        return $out;
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
