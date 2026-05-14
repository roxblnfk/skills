<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use LLM\Skills\Config\VendorConfig;

/**
 * Output of {@see DonorDiscovery::discover()}.
 *
 * Four channels:
 *
 * - `donors`       — every successfully mapped **declared** donor package.
 * - `discoverable` — donors synthesised by {@see AutoDiscoveryProbe} for
 *                    packages that do not declare `extra.skills` but ship a
 *                    `skills/` directory. Always populated regardless of the
 *                    `--discovery` flag: when the flag is off these are
 *                    ignored for sync but their count drives the
 *                    "rerun with --discovery" hint.
 * - `malformed`    — donors whose `extra.skills` block existed but failed
 *                    validation. Structured for consumers that want to
 *                    render them (e.g. the `show` command lists them under
 *                    `Skipped:` with a `malformed` reason code).
 * - `warnings`     — human-readable diagnostics for IO emission. Includes
 *                    the same messages as `malformed` PLUS context-less
 *                    failures like "install path unavailable" that have no
 *                    typed sibling.
 *
 * `warnings` and `malformed` overlap on purpose: the former is the
 * "for printing" view, the latter is the "for structure" view.
 *
 * @psalm-immutable
 */
final readonly class DonorDiscoveryResult
{
    /**
     * @param list<VendorConfig> $donors
     * @param list<string> $warnings
     * @param list<MalformedDonor> $malformed
     * @param list<VendorConfig> $discoverable
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public array $donors,
        public array $warnings,
        public array $malformed = [],
        public array $discoverable = [],
    ) {}
}
