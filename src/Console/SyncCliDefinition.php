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
 * Shared CLI surface for `skills:update` — used by both the Composer plugin
 * entrypoint ({@see \LLM\Skills\Composer\Command\Sync}) and the standalone
 * binary ({@see \LLM\Skills\Console\Command\Sync}).
 *
 * Centralising `configure()` and `buildOptions()` here guarantees that the
 * two entrypoints accept the exact same arguments and flags. Adding a new
 * option in one place is impossible — it has to land in this file.
 */
final class SyncCliDefinition
{
    private const DESCRIPTION = 'Update AI skills from vendor packages into the project';

    /**
     * Decorate a Symfony Console command with `update` arguments and
     * options. The name is passed explicitly because each entrypoint
     * registers the command under its own identifier — the Composer plugin
     * uses `skills:update`, the standalone binary uses `update`.
     *
     * @param non-empty-string $name
     * @param list<non-empty-string> $aliases extra names the same command answers to (e.g. `u` for the
     *         standalone binary, `skills:u` for the Composer plugin)
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
                'Restrict sync to matching packages (exact "vendor/pkg" or wildcard "vendor/*"). '
                . 'When omitted, every installed donor package is considered.',
            )
            ->addOption(
                'target',
                't',
                InputOption::VALUE_REQUIRED,
                'Destination directory for synced skills. Overrides extra.skills.target.',
            )
            ->addOption(
                'alias',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Additional path that should be created as a junction (Windows) or '
                . 'symlink (POSIX) pointing at the target. Repeatable. Passing the flag '
                . 'at all replaces extra.skills.aliases entirely (no merging).',
            )
            ->addOption(
                'trust',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Trust an additional package or vendor for this run only (repeatable).',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Print what would happen without touching the filesystem.',
            )
            ->addOption(
                'discovery',
                $discoveryShortFlag ? 'd' : null,
                InputOption::VALUE_NONE,
                'Also include packages that do not declare extra.skills but ship a skills/ '
                . 'directory. Overrides extra.skills.discovery.',
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

        $aliasOverrides = self::parseAliases($input);

        return new SyncOptions(
            packageFilters: self::parsePatterns($rawPackages, 'package argument'),
            extraTrusted: self::parsePatterns($rawTrust, '--trust option'),
            targetOverride: $targetOverride,
            interactive: $input->isInteractive(),
            dryRun: (bool) $input->getOption('dry-run'),
            discovery: $input->getOption('discovery') === true ? true : null,
            aliasOverrides: $aliasOverrides,
        );
    }

    /**
     * Convert `--alias=PATH` entries into an override list. Returns `null`
     * when the flag was not present at all (so the runner can fall back to
     * project config); returns a `list<non-empty-string>` (possibly empty)
     * once the flag has been used, signalling an explicit takeover.
     *
     * @return list<non-empty-string>|null
     *
     * @throws \InvalidArgumentException when an alias value is not a non-empty string
     */
    private static function parseAliases(InputInterface $input): ?array
    {
        $raw = (array) $input->getOption('alias');
        if ($raw === []) {
            return null;
        }

        $out = [];
        foreach ($raw as $value) {
            if (!\is_string($value) || $value === '') {
                throw new \InvalidArgumentException('--alias option must be a non-empty string');
            }
            $out[] = $value;
        }

        return $out;
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
}
