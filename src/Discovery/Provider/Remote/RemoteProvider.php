<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote;

use Internal\Path;
use LLM\Skills\Config\Exception\MalformedVendorConfig;
use LLM\Skills\Config\Mapper\VendorConfigMapper;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Discovery\AutoDiscoveryProbe;
use LLM\Skills\Discovery\DonorDiscoveryResult;
use LLM\Skills\Discovery\MalformedDonor;
use LLM\Skills\Discovery\Provider\DonorProvider;

/**
 * {@see DonorProvider} for donors fetched from arbitrary repository
 * URLs — anything Composer's VCS layer can resolve
 * (GitHub/GitLab/Bitbucket HTTPS or SSH, plain `git://`, Mercurial,
 * etc.).
 *
 * Pipeline: the {@see RemoteDonorSource} produces {@see RemoteDonorRef}s
 * (the "what to fetch" list — sourced from `skills.json`, vendor
 * declarations, or a future `skills:add` lockfile), the
 * {@see RemoteFetcher} resolves each into a local extracted archive
 * (the "how to fetch" — HTTP archive download, full `git clone`, etc.),
 * and this provider then treats every extracted root identically to a
 * Composer-installed package: parses its `composer.json`, runs
 * {@see VendorConfigMapper} against `extra.skills`, emits the same
 * {@see DonorDiscoveryResult} shape as
 * {@see \LLM\Skills\Discovery\Provider\ComposerProvider}.
 *
 * Each failure mode degrades gracefully:
 *
 * - Source empty                       → provider reports `isActive() = false`,
 *                                        discover returns empty (no warnings).
 * - Fetcher missing but source non-empty → one warning, no donors.
 * - Per-ref fetch error                 → warning naming the ref, skip ref.
 * - Per-ref composer.json missing /
 *   unreadable / invalid JSON /
 *   non-object / `name` missing /
 *   `extra.skills.source` missing       → warning naming the ref, skip ref.
 * - Per-ref `extra.skills` malformed    → warning + structured
 *                                        {@see MalformedDonor} entry, skip ref.
 *
 * One bad ref never blocks the rest — same contract as the Composer
 * provider, expressed against a different ecosystem.
 */
final readonly class RemoteProvider implements DonorProvider
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private RemoteDonorSource $source = new NullRemoteDonorSource(),
        private ?RemoteFetcher $fetcher = null,
        private VendorConfigMapper $vendorMapper = new VendorConfigMapper(),
        private AutoDiscoveryProbe $autoDiscovery = new AutoDiscoveryProbe(),
    ) {}

    #[\Override]
    public function isActive(Path $projectRoot): bool
    {
        // Cheap config-level check (no ref resolution / no HTTP).
        // {@see RemoteDonorSource::hasRefs()} answers from `skills.json`
        // directly; iterating `refs()` here would double-up the network
        // traffic with the subsequent `discover()` call.
        return $this->source->hasRefs($projectRoot);
    }

    #[\Override]
    public function discover(Path $projectRoot): DonorDiscoveryResult
    {
        $donors = [];
        /** @var list<string> $warnings */
        $warnings = [];
        $malformed = [];

        if ($this->fetcher === null) {
            // Active source but no fetcher wired — one warning is more
            // useful than one-per-ref, since the misconfiguration is
            // global, not per-donor.
            foreach ($this->source->refs($projectRoot) as $_ref) {
                $warnings[] = 'remote donor source declared refs but no fetcher is configured';
                break;
            }
            return new DonorDiscoveryResult(donors: [], warnings: $warnings);
        }

        foreach ($this->source->refs($projectRoot) as $ref) {
            try {
                $path = $this->fetcher->fetch($ref);
            } catch (RemoteFetchException $e) {
                $warnings[] = \sprintf('remote %s: %s', $ref->describe(), $e->getMessage());
                continue;
            }

            $donor = $this->buildDonor($ref, $path, $warnings, $malformed);
            if ($donor !== null) {
                $donors[] = $donor;
            }
        }

        // Pre-fetch warnings from the source: unknown-adapter and
        // resolve errors accumulate here while {@see RemoteDonorSource::refs()}
        // is iterated; the source's contract is to expose them after
        // iteration finishes.
        foreach ($this->source->warnings() as $w) {
            $warnings[] = $w;
        }

        return new DonorDiscoveryResult(
            donors: $donors,
            warnings: $warnings,
            malformed: $malformed,
        );
    }

    /**
     * Remote refs are external donors, not Composer dependencies of
     * the consumer project, so the `directDependencies` channel
     * (rooted in Composer's `require` / `require-dev` semantics)
     * does not apply. Implicit-trust for `remote[]` entries flows
     * through {@see \LLM\Skills\Config\VendorConfig::$implicitTrust}
     * instead — set in {@see self::discover()} on every donor this
     * provider emits — which the planner checks before consulting
     * the trust list.
     *
     * @psalm-suppress MissingPureAnnotation
     *         the inferred-pure body is incidental; the interface contract is impure
     */
    #[\Override]
    public function directDependencies(Path $projectRoot): array
    {
        return [];
    }

    /**
     * Resolve a single ref into a `VendorConfig`, or `null` when the
     * archive cannot be turned into a donor. Accumulates per-ref
     * warnings + malformed entries into the caller's lists.
     *
     * Two acceptable archive shapes:
     *
     * 1. **Composer-shaped donor** — `composer.json` is present and
     *    declares `name` + `extra.skills.source`. The donor's package
     *    name comes from `composer.json`'s `name` field. This is the
     *    primary supported shape for ecosystems that already publish
     *    a Composer manifest.
     *
     * 2. **Bare skill repo** — no `composer.json` (or one without
     *    `extra.skills.source`), but the archive ships a top-level
     *    `skills/` directory. The donor's package name falls back to
     *    `RemoteDonorRef::$packageHint` (set from the entry's
     *    adapter-side identifier — for GitHub, `<owner>/<repo>`).
     *    Mirrors the local-provider auto-discovery path for ad-hoc
     *    skill packs that don't bother with a Composer manifest.
     *
     * @param list<string>             $warnings  appended to in place
     * @param list<MalformedDonor>     $malformed appended to in place
     *
     * @param-out list<string>         $warnings
     * @param-out list<MalformedDonor> $malformed
     */
    private function buildDonor(
        RemoteDonorRef $ref,
        Path $path,
        array &$warnings,
        array &$malformed,
    ): ?VendorConfig {
        $composerJsonPath = (string) $path->join('composer.json');
        $extra = null;
        $packageName = null;

        if (\is_file($composerJsonPath)) {
            $contents = \file_get_contents($composerJsonPath);
            if ($contents === false) {
                $warnings[] = \sprintf(
                    'remote %s: failed to read composer.json',
                    $ref->describe(),
                );
                return null;
            }

            try {
                /** @var mixed $decoded */
                $decoded = \json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $warnings[] = \sprintf(
                    'remote %s: composer.json is not valid JSON: %s',
                    $ref->describe(),
                    $e->getMessage(),
                );
                return null;
            }

            if (!\is_array($decoded)) {
                $warnings[] = \sprintf(
                    'remote %s: composer.json must be a JSON object',
                    $ref->describe(),
                );
                return null;
            }

            /** @var mixed $rawName */
            $rawName = $decoded['name'] ?? null;
            if (\is_string($rawName) && $rawName !== '' && \str_contains($rawName, '/')) {
                /** @var non-empty-string $packageName */
                $packageName = $rawName;
            }

            /** @var mixed $extra */
            $extra = $decoded['extra'] ?? null;
        }

        // Composer-shaped donor: composer.json present, name well-shaped,
        // extra.skills.source declared. Hand off to the mapper.
        if ($packageName !== null && VendorConfigMapper::declaresSkills($extra)) {
            try {
                $donor = $this->vendorMapper->fromExtra($packageName, $path, $extra);
            } catch (MalformedVendorConfig $e) {
                $warnings[] = $e->getMessage();
                /** @var non-empty-string $reason */
                $reason = \preg_replace('/^Package "[^"]+": /', '', $e->getMessage())
                    ?? $e->getMessage();
                $malformed[] = new MalformedDonor(
                    packageName: $e->packageName,
                    reason: $reason,
                );
                return null;
            }
            return $this->decorate($donor, $ref);
        }

        // Auto-discovery fallback: no usable composer.json metadata,
        // but the archive may ship a bare `skills/` directory. Synthesise
        // a donor using the entry's `packageHint` as the name. Without a
        // hint we have nothing stable to call the donor — abort.
        $source = $this->autoDiscovery->probe($path);
        if ($source === null) {
            $warnings[] = \sprintf(
                'remote %s: archive ships neither a composer.json with extra.skills.source '
                . 'nor a `%s/` directory at the root — not a donor',
                $ref->describe(),
                AutoDiscoveryProbe::SOURCE_DIR,
            );
            return null;
        }
        $synthesisedName = $packageName ?? $ref->packageHint;
        if ($synthesisedName === null) {
            $warnings[] = \sprintf(
                'remote %s: archive has a `%s/` directory but no package name to '
                . 'register it under (composer.json missing AND the adapter could not '
                . 'derive a `vendor/repo` identifier)',
                $ref->describe(),
                AutoDiscoveryProbe::SOURCE_DIR,
            );
            return null;
        }

        // NOTE: `discovered: false` even though the source directory was
        // auto-probed. The `discovered` flag drives "auto-found locally,
        // opt in via --discovery" semantics — but remote entries are
        // already explicit (the user typed `skills:add`). Treating them
        // as discoverable would show a misleading [discovered] badge and
        // gate them behind a flag they shouldn't need.
        return $this->decorate(
            new VendorConfig(
                packageName: $synthesisedName,
                packageRoot: $path,
                source: $source,
            ),
            $ref,
        );
    }

    /**
     * Apply the provenance + implicit-trust + skill-filter that every
     * remote donor inherits from its `RemoteDonorRef`.
     *
     * @psalm-mutation-free
     */
    private function decorate(VendorConfig $donor, RemoteDonorRef $ref): VendorConfig
    {
        $provenance = $ref->provenance ?? 'remote';
        // `remote[]` entries are user-declared and therefore
        // implicit-trusted, regardless of `from` value. The planner's
        // trust list applies to local-provider transitive discoveries
        // only. The optional skill allowlist carries through to the
        // enumerator via {@see VendorConfig::$skillFilter}; `null`
        // keeps the default "sync every skill" behaviour.
        return $donor
            ->withProvenance($provenance)
            ->asImplicitlyTrusted()
            ->withSkillFilter($ref->skillFilter);
    }
}
