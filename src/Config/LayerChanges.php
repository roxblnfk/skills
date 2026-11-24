<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

/**
 * What one config layer folded over the shared configuration changed,
 * e.g. the personal `skills.local.json` over `skills.json`.
 *
 * Only effective changes are recorded: a key the layer sets to the
 * value it already had, or a list item already present below it, does
 * not appear.
 *
 * @psalm-immutable
 */
final class LayerChanges
{
    /**
     * @param non-empty-string $name origin of the layer, e.g. `skills.local.json`
     * @param list<non-empty-string> $summary human-readable change items in key order,
     *        e.g. `aliases +1`, `vendor-sources=false`, `dependencies.composer.trusted +2`
     * @param list<non-empty-string> $changedKeys top-level keys whose value the layer changed
     * @param list<non-empty-string> $addedAliases alias entries the layer contributed
     * @param list<non-empty-string> $sourceIdentities {@see SourceEntry::compositeKey()} of every
     *        `sources[]` entry the layer declares; the merged entry under each of them is the
     *        layer's own
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly string $name,
        public readonly array $summary = [],
        public readonly array $changedKeys = [],
        public readonly array $addedAliases = [],
        public readonly array $sourceIdentities = [],
    ) {}

    /**
     * @param list<non-empty-string> $sourceIdentities
     *
     * @psalm-mutation-free
     */
    public function withSourceIdentities(array $sourceIdentities): self
    {
        return new self($this->name, $this->summary, $this->changedKeys, $this->addedAliases, $sourceIdentities);
    }

    /**
     * @psalm-mutation-free
     */
    public function changed(string $key): bool
    {
        return \in_array($key, $this->changedKeys, true);
    }

    /**
     * One-line description for console output, e.g.
     * `skills.local.json: aliases +1, sources +1, vendor-sources=false`.
     *
     * @psalm-mutation-free
     */
    public function describe(): string
    {
        return $this->name . ': ' . ($this->summary === [] ? 'no effective changes' : \implode(', ', $this->summary));
    }
}
