<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote;

use Internal\Path;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Remote\Adapter\RemoteResolveException;
use LLM\Skills\Discovery\Provider\Remote\Adapter\UnknownAdapterException;

/**
 * {@see RemoteDonorSource} backed by the project's `skills.json`
 * `remote[]` list.
 *
 * Pipeline:
 *
 * 1. Resolve project config (best-effort — malformed config produces
 *    an empty stream; the {@see \LLM\Skills\Sync\SyncRunner} surfaces
 *    the real error on its own config read).
 * 2. For each {@see \LLM\Skills\Config\RemoteEntry}, look up the
 *    adapter via {@see HostAdapterRegistry}.
 * 3. Ask the adapter to {@see \LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapter::resolve()}
 *    the entry into a fetchable {@see RemoteDonorRef} — concrete
 *    archive URL plus concrete tag / branch / SHA.
 * 4. Yield the ref. Resolution errors (unknown adapter, no matching
 *    tag, transport failure during tag listing) become warnings via
 *    {@see self::warnings()} and the offending entry is skipped.
 *
 * The source carries mutable state — the warnings list is populated
 * as the iterator runs — so the class is intentionally NOT `readonly`
 * and NOT `@psalm-immutable`. The {@see RemoteProvider} consumes the
 * iterable to exhaustion before reading the warnings, so there is no
 * concurrency hazard.
 *
 * Per-entry isolation: one failing entry never blocks the rest.
 */
final class SkillsJsonRemoteDonorSource implements RemoteDonorSource
{
    /** @var list<string> */
    private array $lastWarnings = [];

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly HostAdapterRegistry $registry,
        private readonly ProjectConfigMapper $mapper = new ProjectConfigMapper(),
    ) {}

    /**
     * @return iterable<RemoteDonorRef>
     */
    #[\Override]
    public function refs(Path $projectRoot): iterable
    {
        $this->lastWarnings = [];

        try {
            $config = $this->mapper->forProject($projectRoot, null)->config;
        } catch (\Throwable) {
            return;
        }

        foreach ($config->remote as $entry) {
            try {
                $adapter = $this->registry->get($entry->from);
            } catch (UnknownAdapterException $e) {
                $this->lastWarnings[] = \sprintf(
                    'remote %s:%s skipped — %s',
                    $entry->from,
                    $entry->identifier(),
                    $e->getMessage(),
                );
                continue;
            }

            try {
                $resolved = $adapter->resolve($entry);
                // Tag the ref with the entry's adapter id so the
                // downstream provenance carries through to VendorConfig
                // (spec §6.2 --from filter). The adapter itself does
                // not know its own id at resolve time; the source does.
                yield new RemoteDonorRef(
                    url: $resolved->url,
                    ref: $resolved->ref,
                    provenance: $entry->from,
                );
            } catch (RemoteResolveException $e) {
                $this->lastWarnings[] = $e->getMessage();
                continue;
            }
        }
    }

    /**
     * Warnings accumulated during the most recent {@see self::refs()}
     * iteration. Cleared at the start of each iteration so the provider
     * never sees stale entries.
     *
     * @return list<string>
     */
    #[\Override]
    public function warnings(): array
    {
        return $this->lastWarnings;
    }
}
