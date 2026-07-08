<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

use LLM\Skills\Discovery\Provider\ProviderId;

/**
 * Parsed configuration for a single package manager under the
 * `dependencies` block: whether its installed tree is walked for donors
 * and which patterns extend (or replace) its trust.
 *
 * Trust patterns are stored raw — one representation for every manager —
 * because the grammars differ: composer uses `vendor/pkg` names that
 * {@see VendorPattern} understands, whereas npm (`@scope/pkg`) and go
 * (`github.com/owner/mod`) names are strings {@see VendorPattern} rejects
 * by design. Composer patterns are validated through {@see VendorPattern}
 * at map time and exposed as a {@see TrustedVendors} view via
 * {@see self::trustedVendors()}; npm/go patterns are kept as raw strings
 * until their providers land.
 *
 * @psalm-immutable
 */
final readonly class DependencyConfig
{
    /**
     * @param bool|null $enabled resolved enable flag, or `null` when the user left
     *        it out — callers fall back to {@see ProviderId::defaultLocalEnabled()}
     * @param list<non-empty-string> $trusted raw per-manager trust patterns, validated
     *        against the manager's grammar at map time
     * @param bool $trustedReplace when true, `$trusted` fully replaces the manager's
     *        built-in list and its direct-dependency implicit trust
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public ?bool $enabled,
        public array $trusted,
        public bool $trustedReplace,
    ) {}

    /**
     * Resolve `$enabled` against the per-manager default for the given id.
     *
     * @psalm-mutation-free
     */
    public function isEnabled(string $id): bool
    {
        return $this->enabled ?? ProviderId::defaultLocalEnabled($id);
    }

    /**
     * Composer-grammar view of `$trusted`. Only meaningful for the
     * `composer` manager, whose patterns were validated through
     * {@see VendorPattern} at map time; other managers store
     * registry-specific strings {@see VendorPattern} rejects by design.
     *
     * @psalm-mutation-free
     */
    public function trustedVendors(): TrustedVendors
    {
        return TrustedVendors::fromStrings(...$this->trusted);
    }
}
