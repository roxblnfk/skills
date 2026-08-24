<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

/**
 * A `sources[]` entry that could not be turned into a donor.
 *
 * The typed sibling of the plain-string warnings stream, in the same
 * spirit as {@see MalformedDonor} — except this one covers the failures
 * that happen *before* a donor exists at all: an unknown adapter, a
 * constraint with no matching tag, a 404 on the archive endpoint, an
 * archive that turns out not to be a donor. Those used to live only in
 * {@see DonorDiscoveryResult::$warnings}, which is emitted under `-v`,
 * so a donor could vanish from a sync without a single line of output at
 * default verbosity.
 *
 * Split into a short `summary` and an optional long `detail` because the
 * two have different readers: the summary is one row in the sync
 * listing, next to the packages that *did* sync, and has to stay
 * scannable; the detail carries the underlying transport / parser text
 * for whoever reruns with `-v`.
 *
 * @psalm-immutable
 */
final readonly class SourceFailure
{
    /**
     * @param non-empty-string $label donor identity to print as the row header —
     *        `<from>:<identifier>` (e.g. `github:acme/skills`, `dir:./skills`). Not the
     *        donor's Composer name: the failure happened before any `composer.json`
     *        could be read, so this adapter-namespaced spelling is the only identity
     *        available, and it is also the one the user wrote in `skills.json`.
     * @param non-empty-string $summary one-clause cause, phrased to read after the label
     *        (`no tag matches ^9.0`, `archive fetch failed for ref v1.2.3`)
     * @param string|null $detail underlying error text — transport message, parser
     *        error, whatever the layer below produced. `null` when the summary already
     *        says everything there is to say.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $label,
        public string $summary,
        public ?string $detail = null,
    ) {}

    /**
     * Single-line rendering for the plain-string warnings stream, so a
     * failure reads the same under `-v` as it does in the summary
     * listing.
     *
     * @return non-empty-string
     *
     * @psalm-mutation-free
     */
    public function describe(): string
    {
        $line = 'source ' . $this->label . ': ' . $this->summary;

        return $this->detail === null || $this->detail === ''
            ? $line
            : $line . ' — ' . $this->detail;
    }
}
