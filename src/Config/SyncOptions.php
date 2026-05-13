<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

/**
 * Runtime options derived from CLI input (positional args + flags).
 *
 * Kept separate from {@see ProjectConfig} because their lifetimes and
 * provenance differ: project config is durable (lives in `composer.json`),
 * sync options are per-invocation (live in `argv`).
 *
 * @psalm-immutable
 */
final readonly class SyncOptions
{
    /**
     * @param list<VendorPattern>    $packageFilters positional `<package>` args; empty list means "all donor packages"
     * @param list<VendorPattern>    $extraTrusted   `--trust=` entries, added on top of project + builtin trust lists
     * @param non-empty-string|null  $targetOverride `--target=` override (raw, resolved against `getcwd()` later)
     * @param bool                   $interactive    propagated from {@see \Symfony\Component\Console\Input\InputInterface::isInteractive()}
     * @param bool                   $dryRun         when `true`, the runner prints what would happen but does not write any files
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public array $packageFilters,
        public array $extraTrusted,
        public ?string $targetOverride,
        public bool $interactive,
        public bool $dryRun = false,
    ) {}

    /**
     * @psalm-pure
     */
    public static function default(): self
    {
        return new self(
            packageFilters: [],
            extraTrusted: [],
            targetOverride: null,
            interactive: false,
            dryRun: false,
        );
    }

    /**
     * `true` when the user specified one or more positional package patterns;
     * `false` when sync should consider every installed donor package.
     *
     * @psalm-mutation-free
     */
    public function hasPackageFilters(): bool
    {
        return $this->packageFilters !== [];
    }

    /**
     * @param non-empty-string $packageName
     *
     * @psalm-mutation-free
     */
    public function matchesFilter(string $packageName): bool
    {
        if ($this->packageFilters === []) {
            return true;
        }

        foreach ($this->packageFilters as $pattern) {
            if ($pattern->matches($packageName)) {
                return true;
            }
        }

        return false;
    }
}
