<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Source;

use Composer\Composer;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Internal\Path;
use LLM\Skills\Config\Exception\MalformedVendorConfig;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Config\Mapper\VendorConfigMapper;
use LLM\Skills\Config\SourceEntry;
use LLM\Skills\Discovery\Provider\ProviderId;
use LLM\Skills\Discovery\Provider\Source\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Source\Adapter\RemoteResolveException;
use LLM\Skills\Discovery\Provider\Source\Adapter\UnknownAdapterException;
use LLM\Skills\Discovery\SourceFailure;

/**
 * {@see DonorRefSource} backed by the `extra.skills.sources` lists of
 * the **installed vendor packages** — the donor side of the external
 * source feature: a package whose skills live outside the package
 * itself (a separate skills repository, a bundle shared by several
 * integration packages) advertises them with the same `sources[]`
 * vocabulary the project uses in `skills.json`.
 *
 * Differences from {@see SkillsJsonDonorRefSource}, all following from
 * the entries being third-party input rather than something the user
 * typed:
 *
 * - every ref carries {@see RemoteDonorRef::$declaredBy}, so the donor
 *   is **not** implicit-trusted — {@see \LLM\Skills\Sync\SyncPlanner}
 *   judges it by the declaring package's name;
 * - the {@see SourceEntry::SELF_VERSION} ref alias resolves against the
 *   declaring package's installed version before the adapter runs;
 * - entries are deduplicated **across packages** by composite key +
 *   resolved ref: several integration packages pointing at one shared
 *   bundle produce a single fetch, with their `skills` allowlists
 *   unioned (an absent allowlist means "all skills" and absorbs the
 *   union). Without this, a shared bundle would conflict with itself
 *   at sync time;
 * - the whole source honours the project-level `vendor-sources` toggle
 *   (`skills.json`, default `true`) and stays silent when it is off —
 *   the same contract as `dependencies.composer: false`.
 *
 * The `sources` list of a *fetched archive* is never read — depth is
 * exactly one, no remote → remote chains. Per-entry isolation matches
 * the sibling source: one malformed package or unresolvable entry never
 * blocks the rest.
 *
 * The failure list is populated as the iterator runs, so the class is
 * intentionally NOT `readonly`; {@see SourceProvider} consumes the
 * iterable to exhaustion before reading the failures.
 */
final class VendorDeclaredRefSource implements DonorRefSource
{
    /** @var list<SourceFailure> */
    private array $lastFailures = [];

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly ?Composer $composer,
        private readonly HostAdapterRegistry $registry,
        private readonly VendorConfigMapper $vendorMapper = new VendorConfigMapper(),
        private readonly ProjectConfigMapper $projectMapper = new ProjectConfigMapper(),
    ) {}

    /**
     * Bind a {@see SourceEntry::SELF_VERSION} ref to the declaring
     * package's installed version, following Composer's own version
     * grammar:
     *
     * - `dev-<branch>` → the branch itself (`dev-main` → `main`);
     * - `<X.Y…>-dev` (numeric-branch form) → the branch (`1.x-dev` → `1.x`);
     * - anything else is a released version → an exact constraint
     *   (`1.4.2` → `=1.4.2`) the adapter resolves against the tag list
     *   by normalized equality, so the skills repository may spell the
     *   tag with or without the `v` prefix.
     *
     * Returns `null` when the version cannot be turned into a ref
     * (empty or branch-less strings) — the caller reports a failure and
     * skips the entry.
     *
     * @return non-empty-string|null
     *
     * @psalm-pure
     */
    public static function resolveSelfVersion(string $prettyVersion): ?string
    {
        if ($prettyVersion === '') {
            return null;
        }

        if (\str_starts_with($prettyVersion, 'dev-')) {
            $branch = \substr($prettyVersion, 4);
            return $branch !== '' ? $branch : null;
        }

        if (\str_ends_with($prettyVersion, '-dev')) {
            $branch = \substr($prettyVersion, 0, -4);
            return $branch !== '' ? $branch : null;
        }

        return '=' . $prettyVersion;
    }

    /**
     * @return iterable<RemoteDonorRef>
     */
    #[\Override]
    public function refs(Path $projectRoot): iterable
    {
        $this->lastFailures = [];

        if ($this->composer === null || !$this->vendorSourcesEnabled($projectRoot)) {
            return;
        }

        /**
         * Declarations surviving the per-package parse, merged across
         * packages. Keyed by composite key + resolved ref so two
         * packages pinning the same bundle at the same ref collapse
         * into one fetch, while different pins stay separate donors.
         *
         * @var array<string, array{
         *     entry: SourceEntry,
         *     ref: non-empty-string|null,
         *     skills: list<non-empty-string>|null,
         *     declaredBy: non-empty-list<non-empty-string>,
         *     hasFilter: bool,
         * }> $merged
         */
        $merged = [];

        foreach ($this->declaringPackages() as $package) {
            /** @var non-empty-string $name */
            $name = $package->getName();

            try {
                $entries = $this->vendorMapper->sourceEntriesFromExtra($name, $package->getExtra());
            } catch (MalformedVendorConfig $e) {
                /** @var non-empty-string $reason */
                $reason = \preg_replace('/^Package "[^"]+": /', '', $e->getMessage())
                    ?? $e->getMessage();
                $this->lastFailures[] = new SourceFailure(
                    label: ProviderId::COMPOSER . ':' . $name,
                    summary: 'invalid extra.skills.sources',
                    detail: $reason,
                );
                continue;
            }

            foreach ($entries as $entry) {
                $ref = $entry->ref;
                if ($ref === SourceEntry::SELF_VERSION) {
                    $ref = self::resolveSelfVersion($package->getPrettyVersion());
                    if ($ref === null) {
                        $this->lastFailures[] = new SourceFailure(
                            label: $this->entryLabel($name, $entry),
                            summary: \sprintf(
                                'cannot bind self.version — package version "%s" is not usable as a ref',
                                $package->getPrettyVersion(),
                            ),
                        );
                        continue;
                    }
                }

                $key = $entry->compositeKey() . '|' . ($ref ?? '');
                if (!isset($merged[$key])) {
                    $merged[$key] = [
                        'entry' => $entry,
                        'ref' => $ref,
                        'skills' => $entry->skills,
                        'declaredBy' => [$name],
                        'hasFilter' => $entry->skills !== null,
                    ];
                    continue;
                }

                // Shared bundle: every declarer is recorded, so the
                // planner can approve the donor when any of them is
                // trusted (or positionally named) — not just whichever
                // package the repository happened to list first.
                $merged[$key]['declaredBy'][] = $name;

                // Union the allowlists. An absent list means "every
                // skill" and absorbs the union; otherwise merge
                // preserving first-seen order.
                if ($merged[$key]['hasFilter'] && $entry->skills !== null) {
                    $merged[$key]['skills'] = \array_values(\array_unique(
                        [...($merged[$key]['skills'] ?? []), ...$entry->skills],
                    ));
                } else {
                    $merged[$key]['skills'] = null;
                    $merged[$key]['hasFilter'] = false;
                }
            }
        }

        foreach ($merged as $declaration) {
            $entry = $declaration['entry'];
            $declaredBy = $declaration['declaredBy'];

            try {
                $adapter = $this->registry->get($entry->from);
            } catch (UnknownAdapterException $e) {
                $this->lastFailures[] = new SourceFailure(
                    label: $this->entryLabel($declaredBy, $entry),
                    summary: 'unknown source adapter',
                    detail: $e->getMessage(),
                );
                continue;
            }

            // The adapter sees the entry with the ref already bound
            // (self.version rewritten to a branch or exact constraint)
            // and the allowlist already unioned across declarers.
            $effective = new SourceEntry(
                from: $entry->from,
                package: $entry->package,
                url: $entry->url,
                host: $entry->host,
                ref: $declaration['ref'],
                extras: $entry->extras,
                skills: $declaration['skills'],
            );

            try {
                $resolved = $adapter->resolve($effective);
            } catch (RemoteResolveException $e) {
                $this->lastFailures[] = new SourceFailure(
                    label: $this->entryLabel($declaredBy, $entry),
                    summary: $e->reason,
                );
                continue;
            }

            yield new RemoteDonorRef(
                url: $resolved->url,
                ref: $resolved->ref,
                provenance: $entry->from,
                skillFilter: $declaration['skills'],
                packageHint: $entry->package,
                declaredBy: $declaredBy,
            );
        }
    }

    /**
     * @return list<SourceFailure>
     */
    #[\Override]
    public function failures(): array
    {
        return $this->lastFailures;
    }

    /**
     * Config-level check: does any installed package declare a
     * non-empty `extra.skills.sources` list, with the project-level
     * `vendor-sources` toggle on? Cheap by design — metadata reads
     * only, no adapter calls, no HTTP.
     */
    #[\Override]
    public function hasRefs(Path $projectRoot): bool
    {
        if ($this->composer === null || !$this->vendorSourcesEnabled($projectRoot)) {
            return false;
        }

        foreach ($this->declaringPackages() as $_package) {
            return true;
        }

        return false;
    }

    /**
     * Whether the project allows vendor-declared external sources.
     * Best-effort read: a malformed project config answers `false`
     * here and the runner surfaces the real error on its own read.
     */
    private function vendorSourcesEnabled(Path $projectRoot): bool
    {
        try {
            return $this->projectMapper->forProject($projectRoot, null)->config->vendorSources;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Installed packages that declare `extra.skills.sources`,
     * deduplicated by name — a dev-branch dependency surfaces both as
     * the real package and as Composer's synthetic default-branch
     * alias, and both carry the same declarations.
     *
     * @return iterable<PackageInterface>
     */
    private function declaringPackages(): iterable
    {
        if ($this->composer === null) {
            return;
        }

        $seen = [];
        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            // Unwrap Composer's synthetic branch aliases so
            // `self.version` binds to the real installed version, not
            // the `9999999-dev` placeholder the alias carries.
            if ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }

            $name = $package->getName();
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            if (VendorConfigMapper::declaresSources($package->getExtra())) {
                yield $package;
            }
        }
    }

    /**
     * `<declarer>[, <declarer>…] → <from>:<identifier>` identity for
     * failure rows — names every `composer.json` the entry lives in and
     * the external source it points at.
     *
     * @param non-empty-string|non-empty-list<non-empty-string> $declaredBy
     *
     * @return non-empty-string
     *
     * @psalm-mutation-free
     */
    private function entryLabel(string|array $declaredBy, SourceEntry $entry): string
    {
        $declarers = \is_string($declaredBy) ? $declaredBy : \implode(', ', $declaredBy);

        return $declarers . ' → ' . $entry->from . ':' . $entry->identifier();
    }
}
