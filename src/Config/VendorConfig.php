<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

use Internal\Path;
use LLM\Skills\Discovery\Provider\ProviderId;

/**
 * Configuration declared by a **donor package** under `extra.skills` in its
 * own `composer.json`, paired with the absolute path Composer installed the
 * package at.
 *
 * A donor package is a regular vendor dependency that ships AI skills. The
 * `source` directory is relative to {@see $packageRoot} and contains one
 * subdirectory per skill.
 *
 * Malformed donor configs do **not** abort sync; the mapper throws
 * {@see \LLM\Skills\Config\Exception\MalformedVendorConfig} and the command
 * skips the offending package with a `-v` warning.
 */
final readonly class VendorConfig
{
    /**
     * @param non-empty-string $packageName Composer name, e.g. `acme/skills-pro`
     * @param Path $packageRoot absolute path where Composer installed the package
     * @param non-empty-string $source directory inside the package containing skill subdirs
     * @param bool $discovered `true` when this donor was synthesised by auto-discovery
     *         (the package does not declare `extra.skills`); `false` for declared donors
     * @param non-empty-string $provenance which provider produced this donor — defaults
     *         to {@see ProviderId::COMPOSER} for back-compat. Used by
     *         `skills:update --from=<id>` (spec §6.2) to filter the donor list.
     * @param bool $implicitTrust the user explicitly declared this donor — the trust
     *         list is not consulted (spec §8.3). Set by {@see \LLM\Skills\Discovery\Provider\Remote\RemoteProvider}
     *         for every `remote[]` entry, regardless of `from` value. Local providers
     *         keep this `false` and let {@see \LLM\Skills\Sync\SyncPlanner} run the
     *         per-registry trust check.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $packageName,
        public Path $packageRoot,
        public string $source,
        public bool $discovered = false,
        public string $provenance = ProviderId::COMPOSER,
        public bool $implicitTrust = false,
    ) {}

    /**
     * Absolute path to the directory whose immediate subdirectories are skills.
     */
    public function sourcePath(): Path
    {
        return $this->packageRoot->join($this->source);
    }

    /**
     * Return a copy of this donor tagged with the given provenance id.
     * Used by remote providers to label their donors with the
     * adapter's `from` value (e.g. `github`) so the `--from` filter
     * downstream knows which provider produced each donor.
     *
     * @param non-empty-string $provenance
     *
     * @psalm-mutation-free
     */
    public function withProvenance(string $provenance): self
    {
        return new self(
            packageName: $this->packageName,
            packageRoot: $this->packageRoot,
            source: $this->source,
            discovered: $this->discovered,
            provenance: $provenance,
            implicitTrust: $this->implicitTrust,
        );
    }

    /**
     * Return a copy of this donor flagged as implicit-trusted. The
     * `remote[]` provider calls this on every donor it produces so
     * the planner does not consult the trust list for them
     * (spec §8.3 — user-declared = trusted).
     *
     * @psalm-mutation-free
     */
    public function asImplicitlyTrusted(): self
    {
        if ($this->implicitTrust) {
            return $this;
        }

        return new self(
            packageName: $this->packageName,
            packageRoot: $this->packageRoot,
            source: $this->source,
            discovered: $this->discovered,
            provenance: $this->provenance,
            implicitTrust: true,
        );
    }
}
