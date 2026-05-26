<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote\Adapter;

/**
 * Output of {@see HostAdapter::parseAddInput()}.
 *
 * Carries the four fields that will land in `skills.json`'s
 * `remote[]` after `skills:add` runs:
 *
 * - `from`     — the adapter id (mandatory).
 * - `package`  — adapter-namespaced identifier (or `null` when
 *                the adapter is URL-only).
 * - `url`      — full URL (mutually exclusive with `package`).
 * - `host`     — registry / API host override (`null` ⇒ use
 *                adapter default).
 * - `ref`      — explicit ref the user typed (`null` ⇒ adapter
 *                runs the §4.3 cascade at sync time).
 *
 * Constructed by the adapter and consumed by the `skills:add` runner
 * (Phase 4). Kept separate from {@see \LLM\Skills\Config\RemoteEntry}
 * because the latter is the **stored** shape (carries adapter-specific
 * extras and is loaded by the mapper) while this is the **parsed-CLI**
 * shape that feeds the writer.
 *
 * @psalm-immutable
 */
final readonly class ParsedAddInput
{
    /**
     * @param non-empty-string $from
     * @param non-empty-string|null $package
     * @param non-empty-string|null $url
     * @param non-empty-string|null $host
     * @param non-empty-string|null $ref
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $from,
        public ?string $package,
        public ?string $url,
        public ?string $host,
        public ?string $ref,
    ) {}
}
