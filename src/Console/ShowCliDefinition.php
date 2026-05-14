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
     */
    public static function apply(Command $command, string $name, array $aliases = []): void
    {
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
}
