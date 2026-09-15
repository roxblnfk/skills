<?php

declare(strict_types=1);

namespace LLM\Skills\Console;

use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\VendorPattern;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared CLI surface for `skills:show` — used by both the Composer
 * plugin entrypoint ({@see \LLM\Skills\Composer\Command\Show}) and the
 * standalone binary ({@see \LLM\Skills\Console\Command\Show}).
 *
 * Mirrors {@see SyncCliDefinition} so the two commands accept the same
 * positional pattern + `--target` + `--trust` triple. `show` does not
 * accept `--dry-run` because it is itself read-only.
 */
final class ShowCliDefinition
{
    private const DESCRIPTION = 'List donor skills, grouped by package, with sync status';

    /**
     * @param non-empty-string $name
     * @param list<non-empty-string> $aliases extra names the same command answers to
     * @param bool $discoveryShortFlag whether to register `-d` as the short alias for `--discovery`.
     *         The Composer plugin must pass `false` because Composer reserves `-d` for
     *         `--working-dir`; the standalone binary passes `true`.
     */
    public static function apply(
        Command $command,
        string $name,
        array $aliases = [],
        bool $discoveryShortFlag = true,
    ): void {
        $command
            ->setName($name)
            ->setAliases($aliases)
            ->setDescription(self::DESCRIPTION)
            ->addArgument(
                'packages',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Restrict listing to matching packages (exact "vendor/pkg" or wildcard "vendor/*").',
            )
            ->addOption(
                'target',
                't',
                InputOption::VALUE_REQUIRED,
                'Check sync status against this destination instead of the configured one.',
            )
            ->addOption(
                'trust',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Trust an additional package or vendor for this listing only (repeatable).',
            )
            ->addOption(
                'discovery',
                $discoveryShortFlag ? 'd' : null,
                InputOption::VALUE_NONE,
                'Include packages that do not declare extra.skills but ship a skills/ '
                . 'directory. On by default; this flag forces it on for a project that '
                . 'turned it off.',
            )
            ->addOption(
                'no-discovery',
                null,
                InputOption::VALUE_NONE,
                'Consider only packages that declare extra.skills. Overrides the '
                . 'discovery setting for this run.',
            );
    }

    /**
     * @throws \InvalidArgumentException when a `packages` or `--trust` pattern is malformed
     */
    public static function buildOptions(InputInterface $input): SyncOptions
    {
        $rawPackages = (array) $input->getArgument('packages');
        $rawTrust = (array) $input->getOption('trust');

        /** @var mixed $rawTarget */
        $rawTarget = $input->getOption('target');
        $targetOverride = \is_string($rawTarget) && $rawTarget !== '' ? $rawTarget : null;

        return new SyncOptions(
            packageFilters: self::parsePatterns($rawPackages, 'package argument'),
            extraTrusted: self::parsePatterns($rawTrust, '--trust option'),
            targetOverride: $targetOverride,
            interactive: $input->isInteractive(),
            dryRun: false,
            discovery: self::discoveryOverride($input),
        );
    }

    /**
     * @param array<array-key, mixed> $raw
     * @param non-empty-string $kind label used in the error message
     *
     * @return list<VendorPattern>
     *
     * @throws \InvalidArgumentException with the original pattern context
     *
     * @psalm-mutation-free
     */
    private static function parsePatterns(array $raw, string $kind): array
    {
        $out = [];
        foreach ($raw as $value) {
            if (!\is_string($value) || $value === '') {
                throw new \InvalidArgumentException(\sprintf('%s must be a non-empty string', $kind));
            }
            try {
                $out[] = VendorPattern::fromString($value);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(\sprintf('%s: %s', $kind, $e->getMessage()), previous: $e);
            }
        }

        return $out;
    }

    /**
     * `--discovery` / `--no-discovery` as a tri-state: `true` / `false`
     * force the setting for this run, `null` defers to the project
     * config. Passing both makes the negative win — the safer of the two
     * is the one that syncs less.
     */
    private static function discoveryOverride(InputInterface $input): ?bool
    {
        if ($input->getOption('no-discovery') === true) {
            return false;
        }

        return $input->getOption('discovery') === true ? true : null;
    }
}
