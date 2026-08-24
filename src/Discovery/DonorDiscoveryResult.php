<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use LLM\Skills\Config\VendorConfig;

/**
 * Output of {@see DonorDiscovery::discover()}.
 *
 * Five channels:
 *
 * - `donors`       — every successfully mapped **declared** donor package.
 * - `discoverable` — donors synthesised by {@see SkillTreeScanner} for
 *                    packages that do not declare `extra.skills` but ship
 *                    `SKILL.md` files in a conventional (or, as a fallback,
 *                    any) location. Always populated regardless of the
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
 *                    typed sibling. Emitted under `-v`.
 * - `failures`     — explicitly declared donors (`sources[]` entries) that
 *                    could not be resolved or fetched at all. Distinct from
 *                    `warnings` in visibility, not just in shape: the user
 *                    asked for these donors by name, so the runners print
 *                    them at default verbosity. See {@see SourceFailure}.
 *
 * `warnings` and `malformed` overlap on purpose: the former is the
 * "for printing" view, the latter is the "for structure" view.
 * `failures` does not overlap either — a donor listed there produced no
 * `warnings` entry, so nothing is printed twice under `-v`.
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
     * @param list<SourceFailure> $failures
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public array $donors,
        public array $warnings,
        public array $malformed = [],
        public array $discoverable = [],
        public array $failures = [],
    ) {}
}
