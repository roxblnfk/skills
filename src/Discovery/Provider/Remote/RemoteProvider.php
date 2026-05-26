<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote;

use Internal\Path;
use LLM\Skills\Config\Exception\MalformedVendorConfig;
use LLM\Skills\Config\Mapper\VendorConfigMapper;
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
    ) {}

    #[\Override]
    public function isActive(Path $projectRoot): bool
    {
        foreach ($this->source->refs($projectRoot) as $_ref) {
            return true;
        }
        return false;
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

            $composerJsonPath = (string) $path->join('composer.json');
            if (!\is_file($composerJsonPath)) {
                $warnings[] = \sprintf(
                    'remote %s: composer.json missing in fetched archive',
                    $ref->describe(),
                );
                continue;
            }

            $contents = \file_get_contents($composerJsonPath);
            if ($contents === false) {
                $warnings[] = \sprintf(
                    'remote %s: failed to read composer.json',
                    $ref->describe(),
                );
                continue;
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
                continue;
            }

            if (!\is_array($decoded)) {
                $warnings[] = \sprintf(
                    'remote %s: composer.json must be a JSON object',
                    $ref->describe(),
                );
                continue;
            }

            /** @var mixed $rawName */
            $rawName = $decoded['name'] ?? null;
            if (!\is_string($rawName) || $rawName === '' || !\str_contains($rawName, '/')) {
                $warnings[] = \sprintf(
                    'remote %s: composer.json must declare a non-empty `name` of the form vendor/package',
                    $ref->describe(),
                );
                continue;
            }
            /** @var non-empty-string $packageName */
            $packageName = $rawName;

            /** @var mixed $extra */
            $extra = $decoded['extra'] ?? null;
            if (!VendorConfigMapper::declaresSkills($extra)) {
                $warnings[] = \sprintf(
                    'remote %s: extra.skills.source is not declared — not a donor',
                    $ref->describe(),
                );
                continue;
            }

            try {
                $donor = $this->vendorMapper->fromExtra($packageName, $path, $extra);
                $provenance = $ref->provenance ?? 'remote';
                // `remote[]` entries are user-declared and therefore
                // implicit-trusted, regardless of `from` value. The
                // planner's trust list applies to local-provider
                // transitive discoveries only. The optional skill
                // allowlist carries through to the enumerator via
                // {@see VendorConfig::$skillFilter}; `null` keeps the
                // default "sync every skill" behaviour.
                $donors[] = $donor
                    ->withProvenance($provenance)
                    ->asImplicitlyTrusted()
                    ->withSkillFilter($ref->skillFilter);
            } catch (MalformedVendorConfig $e) {
                $warnings[] = $e->getMessage();
                /** @var non-empty-string $reason */
                $reason = \preg_replace('/^Package "[^"]+": /', '', $e->getMessage())
                    ?? $e->getMessage();
                $malformed[] = new MalformedDonor(
                    packageName: $e->packageName,
                    reason: $reason,
                );
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
}
