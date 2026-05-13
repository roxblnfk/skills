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
 * Shared CLI surface for `skills:sync` — used by both the Composer plugin
 * entrypoint ({@see \LLM\Skills\Composer\Command\Sync}) and the standalone
 * binary ({@see \LLM\Skills\Console\Command\Sync}).
 *
 * Centralising `configure()` and `buildOptions()` here guarantees that the
 * two entrypoints accept the exact same arguments and flags. Adding a new
 * option in one place is impossible — it has to land in this file.
 */
final class SyncCliDefinition
{
    private const DESCRIPTION = 'Sync AI skills from vendor packages into the project';

    /**
     * Decorate a Symfony Console command with `skills:sync` arguments and
     * options. The name is passed explicitly because each entrypoint
     * registers the command under its own identifier — the Composer plugin
     * uses `skills:sync`, the standalone binary uses `sync`.
     *
     * @param non-empty-string $name
     */
    public static function apply(Command $command, string $name): void
    {
        $command
            ->setName($name)
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
                'trust',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Trust an additional package or vendor for this run only (repeatable).',
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
        );
    }

    /**
     * @param array<array-key, mixed> $raw
     * @param non-empty-string        $kind label used in the error message
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
