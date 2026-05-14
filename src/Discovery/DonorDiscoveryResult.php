<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use LLM\Skills\Config\VendorConfig;

/**
 * Output of {@see DonorDiscovery::discover()}.
 *
 * `donors` lists every successfully mapped donor package. `warnings`
 * carries per-package diagnostics — non-fatal mapping failures, missing
 * install paths, etc. The caller decides how to surface them (typically
 * under `-v` verbosity).
 *
 * @psalm-immutable
 */
final readonly class DonorDiscoveryResult
{
    /**
     * @param list<VendorConfig> $donors
     * @param list<string>       $warnings
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public array $donors,
        public array $warnings,
    ) {}
}
