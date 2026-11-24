<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

/**
 * One raw project config layer, as read from its origin and before
 * mapping: the shared base (`skills.json`, the inline `extra.skills`
 * block, or nothing) or a file layered over it (`skills.local.json`).
 *
 * Layers are folded left to right by {@see ConfigLayerMerger}; keys a
 * layer leaves out stay unset, so "not configured" remains distinct
 * from "configured to the default".
 *
 * @psalm-immutable
 */
final class ConfigLayer
{
    /**
     * @param non-empty-string $name origin of the layer, used as the error prefix and in the
     *        change summary, e.g. `skills.local.json`
     * @param array<array-key, mixed> $values raw top-level keys of the layer
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly string $name,
        public readonly array $values,
    ) {}
}
