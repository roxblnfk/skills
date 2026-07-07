<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote;

use Internal\Path;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Config\SourceEntry;
use LLM\Skills\Discovery\Provider\ProviderId;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Remote\Adapter\RemoteResolveException;
use LLM\Skills\Discovery\Provider\Remote\Adapter\UnknownAdapterException;

/**
 * {@see RemoteDonorSource} backed by the project's `skills.json`
 * `sources[]` list.
 *
 * Pipeline:
 *
 * 1. Resolve project config (best-effort — malformed config produces
 *    an empty stream; the {@see \LLM\Skills\Sync\SyncRunner} surfaces
 *    the real error on its own config read).
 * 2. For each {@see \LLM\Skills\Config\SourceEntry}, look up the
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
     * @return iterable<RemoteDonorRef|DirDonorRef>
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

        foreach ($config->sources as $entry) {
            // Path-only adapters (dir) never hit the host-adapter
            // registry: there is nothing to download or version-resolve,
            // so the entry becomes a {@see DirDonorRef} pointing at a
            // directory the provider reads in place.
            if (ProviderId::isPathOnlySource($entry->from)) {
                yield $this->resolveDirEntry($projectRoot, $entry);
                continue;
            }

            try {
                $adapter = $this->registry->get($entry->from);
            } catch (UnknownAdapterException $e) {
                $this->lastWarnings[] = \sprintf(
                    'source %s:%s skipped — %s',
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
                // (powering the `--from` CLI filter). The adapter itself
                // does not know its own id at resolve time; the source does.
                // The skill allowlist travels alongside the provenance so
                // the donor's {@see VendorConfig::$skillFilter} ends up
                // populated; `null` keeps the legacy "sync every skill"
                // behaviour.
                yield new RemoteDonorRef(
                    url: $resolved->url,
                    ref: $resolved->ref,
                    provenance: $entry->from,
                    skillFilter: $entry->skills,
                    packageHint: $entry->package,
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

    /**
     * Config-level check: does `skills.json` declare any `sources[]`
     * entry? Cheap by design — runs the mapper but **never resolves**
     * refs (no adapter calls, no HTTP). Both `RemoteProvider::isActive()`
     * (every sync / show invocation) and the standalone bootstrap rely
     * on this not roundtripping to GitHub.
     */
    #[\Override]
    public function hasRefs(Path $projectRoot): bool
    {
        try {
            $config = $this->mapper->forProject($projectRoot, null)->config;
        } catch (\Throwable) {
            return false;
        }
        return $config->sources !== [];
    }

    /**
     * Derive a donor package name from a resolved directory:
     * `<parent-basename>/<basename>`, lowercased (e.g.
     * `D:\git\testo\testo\skills` → `testo/skills`). When the resolved
     * path has no usable parent segment (a filesystem root), fall back
     * to `dir/<basename>`.
     *
     * @return non-empty-string
     */
    private static function deriveDirPackageName(Path $resolved): string
    {
        $basename = $resolved->name();
        $parent = $resolved->parent()->name();
        // `name()` never returns an empty string; a filesystem root
        // still yields a `.`/`..` segment with no usable vendor part.
        $vendor = ($parent === '.' || $parent === '..') ? 'dir' : $parent;

        /** @var non-empty-string $name */
        $name = \strtolower($vendor . '/' . $basename);
        return $name;
    }

    /**
     * Resolve a `dir` entry into a {@see DirDonorRef}.
     *
     * Path resolution: a relative `path` is anchored at the project
     * root (the same anchor `target` uses); an absolute `path`
     * (including a Windows drive letter) is honoured as-is. `..`
     * segments and locations outside the project root are allowed — a
     * `sources[]` entry is an explicit act of trust — so no containment
     * check runs here. Existence is NOT checked at resolve time; the
     * provider decides whether the resolved directory is present when
     * it reads it (a per-entry warning if not).
     *
     * The donor's package name (the {@see DirDonorRef::$packageHint})
     * is the entry's `package` override when present, else a name
     * derived from the resolved absolute path.
     */
    private function resolveDirEntry(Path $projectRoot, SourceEntry $entry): DirDonorRef
    {
        // For a dir entry the identifier IS the path (the constructor
        // guarantees `path` is set), so `identifier()` hands back the
        // spelling as a non-empty string without a redundant null check.
        $spelling = $entry->identifier();
        $typed = Path::create($spelling);
        $resolved = $typed->isAbsolute() ? $typed : $projectRoot->join($typed);

        return new DirDonorRef(
            directory: $resolved,
            spelling: $spelling,
            provenance: $entry->from,
            skillFilter: $entry->skills,
            packageHint: $entry->package ?? self::deriveDirPackageName($resolved),
        );
    }
}
