<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote;

use Internal\Path;

/**
 * Result of {@see DonorArchiveInspector::inspect()} — the shared
 * classification of an extracted archive, in a shape both the
 * `skills:add` path ({@see \LLM\Skills\Add\AddRunner}) and the sync
 * path ({@see RemoteProvider}) can finish off their own way.
 *
 * Exactly one of three outcomes holds, built via the named
 * constructors so invalid combinations are unrepresentable:
 *
 * - {@see self::composerShaped()} — `composer.json` declares `name` +
 *   `extra.skills.source`. Carries the name and the raw `extra` so the
 *   caller can hand it to {@see \LLM\Skills\Config\Mapper\VendorConfigMapper::fromExtra()}.
 * - {@see self::bareSkillRepo()} — no usable manifest, but the archive
 *   ships `SKILL.md` files. Carries the synthesised name, the `source`
 *   container, and the discovered skill directories.
 * - {@see self::rejected()} — not a donor; carries the
 *   {@see DonorArchiveRejection} (and an optional detail line).
 *
 * @psalm-immutable
 */
final readonly class DonorArchiveInspection
{
    /**
     * @param non-empty-string|null $packageName donor package name (accepted outcomes only)
     * @param mixed $extra raw `composer.json` `extra` (composer-shaped only)
     * @param non-empty-string|null $source relative container of the discovered skills
     *        (bare-skill-repo only)
     * @param list<Path> $discoveredSkillDirs absolute skill directories (bare-skill-repo only)
     * @param non-empty-string|null $detail extra context for the rejection message
     *        (e.g. the JSON parse error)
     *
     * @psalm-mutation-free
     */
    private function __construct(
        public bool $isComposerShaped,
        public ?string $packageName,
        public mixed $extra,
        public ?string $source,
        public array $discoveredSkillDirs,
        public ?DonorArchiveRejection $rejection,
        public ?string $detail,
    ) {}

    /**
     * @param non-empty-string $packageName
     *
     * @psalm-pure
     */
    public static function composerShaped(string $packageName, mixed $extra): self
    {
        return new self(
            isComposerShaped: true,
            packageName: $packageName,
            extra: $extra,
            source: null,
            discoveredSkillDirs: [],
            rejection: null,
            detail: null,
        );
    }

    /**
     * @param non-empty-string $packageName
     * @param non-empty-string $source
     * @param list<Path> $discoveredSkillDirs
     *
     * @psalm-pure
     */
    public static function bareSkillRepo(
        string $packageName,
        string $source,
        array $discoveredSkillDirs,
    ): self {
        return new self(
            isComposerShaped: false,
            packageName: $packageName,
            extra: null,
            source: $source,
            discoveredSkillDirs: $discoveredSkillDirs,
            rejection: null,
            detail: null,
        );
    }

    /**
     * @param non-empty-string|null $detail
     *
     * @psalm-pure
     */
    public static function rejected(DonorArchiveRejection $rejection, ?string $detail = null): self
    {
        return new self(
            isComposerShaped: false,
            packageName: null,
            extra: null,
            source: null,
            discoveredSkillDirs: [],
            rejection: $rejection,
            detail: $detail,
        );
    }

    /**
     * @psalm-mutation-free
     */
    public function isRejected(): bool
    {
        return $this->rejection !== null;
    }
}
