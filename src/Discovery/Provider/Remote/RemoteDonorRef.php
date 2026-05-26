<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote;

/**
 * A single remote donor request: a repository URL plus the ref to
 * check out.
 *
 * Deliberately minimal — the URL is whatever Composer's VCS layer
 * accepts (`https://github.com/...`, `git@gitlab.com:...`,
 * `https://bitbucket.org/...`, plain `git://...`), and the ref is a
 * tag, branch, or full SHA. The {@see RemoteFetcher} interprets both.
 *
 * The donor's Composer name is NOT carried here on purpose: it is
 * read from the fetched archive's `composer.json` `name` field, the
 * same way Composer-provided donors get their name from package
 * metadata. Without that field the ref is rejected as malformed —
 * the URL alone is not a stable identifier and host-specific
 * derivations ("owner/repo" from GitHub URLs) would only work for a
 * subset of supported hosts.
 *
 * @psalm-immutable
 */
final readonly class RemoteDonorRef
{
    /**
     * @param non-empty-string $url any URL Composer's VCS layer can resolve
     * @param non-empty-string $ref tag, branch, or commit SHA
     * @param non-empty-string|null $provenance adapter id that produced this ref
     *         (e.g. `github`). Used downstream as the donor's
     *         {@see \LLM\Skills\Config\VendorConfig::$provenance}, which drives
     *         the `--from` filter in spec §6.2. `null` means "unknown" — the
     *         provider will tag the donor with a generic `remote` provenance.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $url,
        public string $ref,
        public ?string $provenance = null,
    ) {}

    /**
     * Stable, host-agnostic identifier for diagnostics emitted before
     * the archive's `composer.json` has been read (and thus before
     * the donor's real Composer name is known).
     *
     * @return non-empty-string
     *
     * @psalm-mutation-free
     */
    public function describe(): string
    {
        return $this->url . '@' . $this->ref;
    }
}
