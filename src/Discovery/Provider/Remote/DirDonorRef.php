<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote;

use Internal\Path;

/**
 * A single local-directory donor request: a resolved directory on the
 * filesystem, declared through a `sources[]` entry with `from: "dir"`.
 *
 * The sibling of {@see RemoteDonorRef} for the path-only adapter. Where
 * a {@see RemoteDonorRef} names something to download (URL + ref), a
 * dir ref names a directory that already exists locally — no fetch, no
 * cache, no unpacker. {@see RemoteProvider} reads the live directory
 * each sync, so there is no snapshot to go stale.
 *
 * The two shapes share the surface {@see RemoteProvider} consumes
 * — `provenance`, `skillFilter`, `packageHint`, and {@see describe()} —
 * so the provider handles both through the same
 * inspect-and-decorate tail; only the "how do we get a `Path`" head
 * differs (fetch vs. read the directory in place).
 *
 * @psalm-mutation-free
 */
final readonly class DirDonorRef
{
    /**
     * @param Path $directory resolved absolute directory the donor lives in — relative
     *        `sources[].path` values are resolved against the project root by the source,
     *        absolute ones are honoured as-is. Read live on every sync.
     * @param non-empty-string $spelling the `path` exactly as the user typed it in
     *        `skills.json`, used only by {@see describe()} so diagnostics echo the
     *        user's spelling rather than the resolved absolute form.
     * @param non-empty-string|null $provenance adapter id that produced this ref
     *        (`dir`). Becomes the donor's {@see \LLM\Skills\Config\VendorConfig::$provenance},
     *        which drives the `--from` CLI filter.
     * @param list<non-empty-string>|null $skillFilter explicit allowlist of skill directory
     *        names to keep. `null` means "sync every skill the directory ships".
     * @param non-empty-string|null $packageHint donor package name to register the
     *        directory under when it ships skills without its own `composer.json` name —
     *        the entry's `package` override when present, else a name derived from the
     *        resolved path (`<parent>/<basename>`).
     */
    public function __construct(
        public Path $directory,
        public string $spelling,
        public ?string $provenance = null,
        public ?array $skillFilter = null,
        public ?string $packageHint = null,
    ) {}

    /**
     * Stable identifier for diagnostics, echoing the user's spelling:
     * `dir ./skills`. Mirrors {@see RemoteDonorRef::describe()} so the
     * `source <ref>: <reason>` warning framing reads uniformly across
     * both ref shapes.
     *
     * @return non-empty-string
     *
     * @psalm-mutation-free
     */
    public function describe(): string
    {
        return 'dir ' . $this->spelling;
    }
}
