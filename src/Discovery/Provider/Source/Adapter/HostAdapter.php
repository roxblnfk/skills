<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Source\Adapter;

use LLM\Skills\Config\SourceEntry;
use LLM\Skills\Discovery\Provider\Source\RemoteDonorRef;

/**
 * Per-source-host plug-in contract.
 *
 * One adapter per `from` value (see {@see \LLM\Skills\Discovery\Provider\ProviderId::SOURCE_IDS}).
 * Each adapter knows:
 *
 * - The id ({@see self::id()}) used in `skills.json` `sources[].from`
 *   and in the `--from=<id>` CLI flag.
 * - The default host URL ({@see self::defaultHost()}) used when an
 *   entry omits `host`.
 * - How to parse CLI input ({@see self::parseAddInput()}) for
 *   `skills:add` — a shorthand like `owner/repo`, a full URL,
 *   or `owner/repo@ref`.
 * - How to resolve a stored entry to a concrete fetchable archive
 *   ({@see self::resolve()}) — implements the ref cascade
 *   (highest stable tag → highest prerelease → default branch HEAD).
 *
 * v1 ships {@see GithubAdapter} only. The interface is the contract
 * future adapters (`gitlab`, `composer`, `npm`, `go`, `skills.sh`,
 * `http`, `zip`) will implement without format migration.
 *
 * Adapters are **not pure** — `resolve()` makes HTTP calls — so the
 * interface is deliberately NOT annotated `@psalm-immutable`.
 *
 * @psalm-suppress MissingInterfaceImmutableAnnotation
 *         implementations talk to remote APIs; the interface itself is mutable on purpose
 */
interface HostAdapter
{
    /**
     * Adapter id: the vocabulary entry from
     * {@see \LLM\Skills\Discovery\Provider\ProviderId::SOURCE_IDS}.
     *
     * @return non-empty-string
     *
     * @psalm-mutation-free
     */
    public function id(): string;

    /**
     * Default API/registry base URL when a `sources[]` entry omits
     * `host`. For `github` this is `https://api.github.com`; for
     * `composer` it's the public Packagist URL; etc.
     *
     * @return non-empty-string
     *
     * @psalm-mutation-free
     */
    public function defaultHost(): string;

    /**
     * Parse `skills:add <input>` user input into a structured form
     * the writer can turn into a `sources[]` entry.
     *
     * The exact `$input` grammar is adapter-defined — `github`
     * accepts `owner/repo`, `https://github.com/owner/repo`, and
     * `owner/repo@ref`; URL-only adapters accept only URLs; etc.
     *
     * `$hostOverride` and `$refOverride` come from the `--host=` /
     * `--ref=` CLI flags. The adapter merges them with anything
     * extracted from `$input` (rejecting conflicts — e.g. both
     * `<input>@ref` and `--ref` is an error).
     *
     * @param string $input may be empty — implementations validate and reject
     * @param non-empty-string|null $hostOverride
     * @param non-empty-string|null $refOverride
     *
     * @throws \InvalidArgumentException on malformed input or conflicting overrides
     *
     * @psalm-suppress MissingAbstractPureAnnotation
     */
    public function parseAddInput(
        string $input,
        ?string $hostOverride = null,
        ?string $refOverride = null,
    ): ParsedAddInput;

    /**
     * Resolve a stored `sources[]` entry into a concrete
     * "what to download" descriptor.
     *
     * - When `$entry->ref` is an explicit tag / branch / SHA, the
     *   returned `RemoteDonorRef` carries it verbatim.
     * - When `$entry->ref` is a caret constraint
     *   (`^1.2.3` / `^1` / `^v1.2.3`), the adapter walks the
     *   adapter's version list and picks the highest matching
     *   stable tag.
     * - When `$entry->ref` is `null`, the adapter walks the
     *   ref cascade: highest stable tag → highest prerelease tag →
     *   default branch HEAD.
     *
     * The returned `RemoteDonorRef::$url` is the URL the fetcher
     * should hit — for `github` it's a zipball URL; for `git`-style
     * adapters it would be the repo URL; etc.
     *
     * @throws RemoteResolveException when the adapter cannot resolve the entry
     *         (no matching tag for a caret, no default branch, API failure, …)
     *
     * @psalm-suppress MissingAbstractPureAnnotation
     */
    public function resolve(SourceEntry $entry): RemoteDonorRef;
}
