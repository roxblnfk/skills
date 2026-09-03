<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Source;

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
     *         the `--from` CLI filter. `null` means "unknown" — the provider
     *         will tag the donor with a generic `source` provenance.
     * @param list<non-empty-string>|null $skillFilter explicit allowlist of skill directory
     *         names to keep from the fetched donor. `null` means "no filter — sync every
     *         skill the donor ships". A non-null list is propagated into the resulting
     *         {@see \LLM\Skills\Config\VendorConfig::$skillFilter} so the skill enumerator
     *         drops everything not on the list and warns about declared-but-missing names.
     * @param non-empty-string|null $packageHint adapter-side identifier the entry was
     *         registered under (e.g. GitHub `<owner>/<repo>`). Used as the donor's
     *         package name when the archive ships skills without a `composer.json` —
     *         the only stable identifier we have for ad-hoc skill repos. `null` for
     *         URL-only entries the adapter couldn't reduce to a vendor/package pair.
     * @param list<non-empty-string> $declaredBy names of the installed Composer packages whose
     *         `extra.skills.sources` produced this ref — several when they point at the same
     *         source and ref and were merged into one fetch. Empty for refs the user declared
     *         directly (project `sources[]`, `skills:add`). Carried through to
     *         {@see \LLM\Skills\Config\VendorConfig::$declaredBy} so trust follows the
     *         declaring packages instead of being implicit, and woven into {@see label()}
     *         so diagnostics name every party.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $url,
        public string $ref,
        public ?string $provenance = null,
        public ?array $skillFilter = null,
        public ?string $packageHint = null,
        public array $declaredBy = [],
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

    /**
     * Short `<from>:<identifier>` identity for the summary listing —
     * `github:acme/skills` rather than {@see describe()}'s full URL.
     * Falls back to the URL when the adapter could not reduce the entry
     * to a `vendor/repo` pair, which is the only identifier a URL-only
     * entry has. Vendor-declared refs are prefixed with the declaring
     * package(s) (`acme/foo → github:acme/skills`,
     * `acme/one, acme/two → github:acme/bundle`) so a failure names
     * every `composer.json` the entry actually lives in.
     *
     * @return non-empty-string
     *
     * @psalm-mutation-free
     */
    public function label(): string
    {
        $own = ($this->provenance ?? 'source') . ':' . ($this->packageHint ?? $this->url);

        return $this->declaredBy === [] ? $own : \implode(', ', $this->declaredBy) . ' → ' . $own;
    }
}
