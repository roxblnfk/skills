<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Source;

use Internal\Path;
use LLM\Skills\Config\Exception\MalformedVendorConfig;
use LLM\Skills\Config\Mapper\VendorConfigMapper;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Discovery\DonorDiscoveryResult;
use LLM\Skills\Discovery\MalformedDonor;
use LLM\Skills\Discovery\Provider\DonorProvider;

/**
 * {@see DonorProvider} for donors fetched from arbitrary repository
 * URLs — anything Composer's VCS layer can resolve
 * (GitHub/GitLab/Bitbucket HTTPS or SSH, plain `git://`, Mercurial,
 * etc.).
 *
 * Pipeline: the {@see DonorRefSource} produces the "what to fetch"
 * list (sourced from `skills.json`, vendor declarations, or a future
 * `skills:add` lockfile) as one of two ref shapes, and this provider
 * turns each into a local directory:
 *
 * - {@see RemoteDonorRef} (URL + ref) → the {@see RemoteFetcher}
 *   resolves it into a local extracted archive ("how to fetch" — HTTP
 *   archive download, full `git clone`, etc.).
 * - {@see DirDonorRef} (a resolved local path, `from: "dir"`) → the
 *   directory is read in place; no fetcher, no cache, no unpacker.
 *
 * From there both shapes share the same tail: this provider treats the
 * directory identically to a Composer-installed package — parses its
 * `composer.json`, runs {@see VendorConfigMapper} against `extra.skills`,
 * and emits the same {@see DonorDiscoveryResult} shape as
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
final readonly class SourceProvider implements DonorProvider
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private DonorRefSource $source = new NullDonorRefSource(),
        private ?RemoteFetcher $fetcher = null,
        private VendorConfigMapper $vendorMapper = new VendorConfigMapper(),
        private DonorArchiveInspector $inspector = new DonorArchiveInspector(),
    ) {}

    #[\Override]
    public function isActive(Path $projectRoot): bool
    {
        // Cheap config-level check (no ref resolution / no HTTP).
        // {@see DonorRefSource::hasRefs()} answers from `skills.json`
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

        // Active source but no fetcher wired — one warning is more
        // useful than one-per-ref, since the misconfiguration is global,
        // not per-donor. Dir refs need no fetcher (they read the live
        // directory), so they are still processed in that case.
        $fetcherWarned = false;

        foreach ($this->source->refs($projectRoot) as $ref) {
            if ($ref instanceof DirDonorRef) {
                $donor = $this->resolveDirRef($ref, $warnings, $malformed);
                if ($donor !== null) {
                    $donors[] = $donor;
                }
                continue;
            }

            if ($this->fetcher === null) {
                if (!$fetcherWarned) {
                    $warnings[] = 'remote donor source declared refs but no fetcher is configured';
                    $fetcherWarned = true;
                }
                continue;
            }

            try {
                $path = $this->fetcher->fetch($ref);
            } catch (RemoteFetchException $e) {
                $warnings[] = \sprintf('source %s: %s', $ref->describe(), $e->getMessage());
                continue;
            }

            $donor = $this->buildDonor($ref, $path, $warnings, $malformed);
            if ($donor !== null) {
                $donors[] = $donor;
            }
        }

        // Pre-fetch warnings from the source: unknown-adapter and
        // resolve errors accumulate here while {@see DonorRefSource::refs()}
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
     * does not apply. Implicit-trust for `sources[]` entries flows
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
     * Resolve a `dir` ref into a `VendorConfig`, or `null` when the
     * directory is absent or not a donor. The "fetch" for a dir ref is
     * simply confirming the directory exists — no download, no cache,
     * no unpacker — and then running the shared inspector against the
     * live directory, exactly as the remote path does against an
     * extracted archive.
     *
     * A missing / non-directory path is graceful degradation, matching
     * a failed remote fetch: a per-entry warning, and the entry is
     * skipped without touching its siblings.
     *
     * @param list<string>             $warnings  appended to in place
     * @param list<MalformedDonor>     $malformed appended to in place
     *
     * @param-out list<string>         $warnings
     * @param-out list<MalformedDonor> $malformed
     */
    private function resolveDirRef(
        DirDonorRef $ref,
        array &$warnings,
        array &$malformed,
    ): ?VendorConfig {
        if (!$ref->directory->isDir()) {
            $warnings[] = \sprintf('source %s: directory does not exist', $ref->describe());
            return null;
        }

        return $this->buildDonor($ref, $ref->directory, $warnings, $malformed);
    }

    /**
     * Resolve a single ref into a `VendorConfig`, or `null` when the
     * archive cannot be turned into a donor. Accumulates per-ref
     * warnings + malformed entries into the caller's lists.
     *
     * The parse-and-classify step is delegated to the shared
     * {@see DonorArchiveInspector} — the same inspector `skills:add`
     * runs when it fetches the archive, so what the two paths accept as
     * a donor never drifts. This method only turns the inspection into
     * a {@see VendorConfig} (or a warning):
     *
     * - **Composer-shaped** → hand the name + raw `extra` to the mapper;
     *   a mapper rejection lifts to a warning AND a {@see MalformedDonor}.
     * - **Bare skill repo** → synthesise a `VendorConfig` from the
     *   auto-discovered skill directories.
     * - **Rejected** → a per-ref warning, no donor.
     *
     * @param list<string>             $warnings  appended to in place
     * @param list<MalformedDonor>     $malformed appended to in place
     *
     * @param-out list<string>         $warnings
     * @param-out list<MalformedDonor> $malformed
     */
    private function buildDonor(
        RemoteDonorRef|DirDonorRef $ref,
        Path $path,
        array &$warnings,
        array &$malformed,
    ): ?VendorConfig {
        $inspection = $this->inspector->inspect($path, $ref->packageHint);

        $rejection = $inspection->rejection;
        if ($rejection !== null) {
            $warnings[] = \sprintf(
                'source %s: %s',
                $ref->describe(),
                $this->describeRejection($rejection, $inspection->detail),
            );
            return null;
        }

        if ($inspection->isComposerShaped) {
            /** @var non-empty-string $packageName */
            $packageName = $inspection->packageName;
            try {
                $donor = $this->vendorMapper->fromExtra($packageName, $path, $inspection->extra);
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

        // Bare skill repo. NOTE: `discovered: false` even though the
        // skills were auto-found. The `discovered` flag drives
        // "auto-found locally, opt in via --discovery" semantics — but
        // remote entries are already explicit (the user typed
        // `skills:add`). Treating them as discoverable would show a
        // misleading [discovered] badge and gate them behind a flag they
        // shouldn't need. The explicit skill directories ride on
        // `discoveredSkillDirs` so the enumerator finds them at whatever
        // depth they live.
        /** @var non-empty-string $packageName */
        $packageName = $inspection->packageName;
        /** @var non-empty-string $source */
        $source = $inspection->source;
        return $this->decorate(
            new VendorConfig(
                packageName: $packageName,
                packageRoot: $path,
                source: $source,
                discoveredSkillDirs: $inspection->discoveredSkillDirs,
            ),
            $ref,
        );
    }

    /**
     * Phrase a {@see DonorArchiveRejection} for a per-ref sync warning.
     * The inspector owns the *classification*; the `source <ref>:`
     * framing is added by the caller.
     *
     * @psalm-pure
     */
    private function describeRejection(DonorArchiveRejection $rejection, ?string $detail): string
    {
        return match ($rejection) {
            DonorArchiveRejection::ComposerJsonUnreadable =>
                'failed to read composer.json',
            DonorArchiveRejection::ComposerJsonInvalidJson =>
                'composer.json is not valid JSON: ' . ($detail ?? ''),
            DonorArchiveRejection::ComposerJsonNotObject =>
                'composer.json must be a JSON object',
            DonorArchiveRejection::NoDonorShape =>
                'archive ships neither a composer.json with extra.skills.source '
                . 'nor any SKILL.md files — not a donor',
            DonorArchiveRejection::NoPackageName =>
                'archive ships SKILL.md files but no package name to register it under '
                . '(composer.json missing AND the adapter could not derive a `vendor/repo` identifier)',
        };
    }

    /**
     * Apply the provenance + implicit-trust + skill-filter that every
     * remote donor inherits from its `RemoteDonorRef`.
     *
     * @psalm-mutation-free
     */
    private function decorate(VendorConfig $donor, RemoteDonorRef|DirDonorRef $ref): VendorConfig
    {
        $provenance = $ref->provenance ?? 'source';
        // `sources[]` entries are user-declared and therefore
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
