<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

use LLM\Skills\Config\TrustedVendors;

/**
 * Answers "which trust list approved this donor?" for the `show`
 * formatter, so it can render `[via built-in trust]` annotations.
 *
 * Holds the three trust sources separately rather than merging them
 * (which is what {@see \LLM\Skills\Sync\SyncPlanner} does internally
 * via `TrustedVendors::merge()`). Merging loses provenance — once the
 * patterns are concatenated, no one remembers which list contributed
 * which pattern. The attributor keeps them apart and walks them in a
 * defined priority order.
 *
 * Priority on match: **project → cli → builtin**. Rationale:
 *
 * - Project trust is the durable, version-controlled answer to "do we
 * trust this vendor". If it covers the donor, that's the canonical
 * source.
 * - CLI `--trust` is a per-invocation override; it ranks above the
 * built-in fallback so users can audit a one-off decision.
 * - Built-in is the silent default. It is only surfaced when nothing
 * else explains the trust.
 *
 * When {@see \LLM\Skills\Config\ProjectConfig::$trustedReplace} is
 * `true` the built-in list is not in effect; pass `null` as `$builtin`
 * to reflect that. Donors that no list matches return `null` from
 * {@see attribute()} — the caller is expected to not pass untrusted
 * donors in the first place.
 *
 * @psalm-immutable
 */
final readonly class TrustAttributor
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private ?TrustedVendors $builtin,
        private TrustedVendors $project,
        private TrustedVendors $cli,
    ) {}

    /**
     * @param non-empty-string $packageName
     *
     * @psalm-mutation-free
     */
    public function attribute(string $packageName): ?TrustSource
    {
        if ($this->project->trusts($packageName)) {
            return TrustSource::Project;
        }
        if ($this->cli->trusts($packageName)) {
            return TrustSource::Cli;
        }
        if ($this->builtin !== null && $this->builtin->trusts($packageName)) {
            return TrustSource::Builtin;
        }

        return null;
    }
}
