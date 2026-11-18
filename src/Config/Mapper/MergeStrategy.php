<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

/**
 * How {@see ConfigLayerMerger} combines a top-level key of an upper
 * config layer with the value accumulated from the layers below it.
 */
enum MergeStrategy
{
    /**
     * The upper layer's value replaces the lower one.
     */
    case Override;

    /**
     * List union: lower items first, then upper items not already
     * present. Aliases compare as paths (separators unified, `.` and empty
     * segments dropped); other lists compare exact values.
     */
    case Union;

    /**
     * `sources[]` union keyed by the source identity
     * ({@see SourceEntryMapper::compositeKeyOf()}, with a `dir` path
     * compared as a path): an upper entry with the identity of a lower
     * one replaces it in place, other upper entries are appended.
     */
    case SourcesByIdentity;

    /**
     * Per-package-manager merge of the `dependencies` map: `enabled` and
     * `trusted-replace` override, `trusted` is a union. A bool entry
     * stands for `{ "enabled": <bool> }`.
     */
    case Dependencies;

    /**
     * Deprecated key accepted only in the base layer; an upper layer
     * carrying it is rejected.
     */
    case Rejected;
}
