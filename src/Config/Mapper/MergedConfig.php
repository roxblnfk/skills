<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

use LLM\Skills\Config\LayerChanges;

/**
 * Result of {@see ConfigLayerMerger::merge()}: the folded raw config,
 * ready for mapping, and what every layer above the base changed.
 *
 * @psalm-immutable
 */
final class MergedConfig
{
    /**
     * @param array<array-key, mixed> $values raw config after all layers were folded
     * @param list<LayerChanges> $changes one record per layer above the base, in fold order
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly array $values,
        public readonly array $changes,
    ) {}
}
