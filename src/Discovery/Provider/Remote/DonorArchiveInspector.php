<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote;

use Internal\Path;
use LLM\Skills\Config\Mapper\VendorConfigMapper;
use LLM\Skills\Discovery\DiscoveredSkill;
use LLM\Skills\Discovery\SkillTreeScanner;

/**
 * Classifies an extracted archive as a skill donor — the single
 * source of truth shared by the two code paths that fetch a remote
 * archive and then have to decide what it is:
 *
 * - {@see \LLM\Skills\Add\AddRunner} validates the archive during
 *   `skills:add` (it only needs the donor's package name to scope the
 *   follow-up sync).
 * - {@see RemoteProvider} does the same during `skills:update`, then
 *   turns the result into a {@see \LLM\Skills\Config\VendorConfig}.
 *
 * Keeping the parse-and-classify rules here — rather than mirrored in
 * both callers — is what stops the two paths from drifting: a drift
 * where `skills:add` accepts an archive the sync then rejects (or vice
 * versa) is exactly what leaves a skill registered but never copied.
 *
 * Two archive shapes are accepted (see {@see DonorArchiveInspection}):
 *
 * 1. **Composer-shaped** — `composer.json` present, `name` well-formed
 *    (`vendor/package`), and `extra.skills.source` declared.
 * 2. **Bare skill repo** — no usable manifest, but `SKILL.md` files
 *    live somewhere in the tree ({@see SkillTreeScanner} probes the
 *    conventional roots first, then recurses). The name falls back to
 *    the adapter-side `$packageHint`.
 *
 * Anything else is a {@see DonorArchiveRejection}.
 */
final readonly class DonorArchiveInspector
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private SkillTreeScanner $scanner = new SkillTreeScanner(),
    ) {}

    /**
     * @param non-empty-string|null $packageHint adapter-side identifier used as the
     *        donor name for the bare-skill-repo shape (for GitHub, `<owner>/<repo>`);
     *        `null` when the adapter could not derive one.
     */
    public function inspect(Path $extractedRoot, ?string $packageHint): DonorArchiveInspection
    {
        $packageName = null;
        $extra = null;

        $composerJsonPath = (string) $extractedRoot->join('composer.json');
        if (\is_file($composerJsonPath)) {
            $contents = \file_get_contents($composerJsonPath);
            if ($contents === false) {
                return DonorArchiveInspection::rejected(DonorArchiveRejection::ComposerJsonUnreadable);
            }

            try {
                /** @var mixed $decoded */
                $decoded = \json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $detail = $e->getMessage();
                return DonorArchiveInspection::rejected(
                    DonorArchiveRejection::ComposerJsonInvalidJson,
                    $detail !== '' ? $detail : null,
                );
            }

            if (!\is_array($decoded)) {
                return DonorArchiveInspection::rejected(DonorArchiveRejection::ComposerJsonNotObject);
            }

            /** @var mixed $rawName */
            $rawName = $decoded['name'] ?? null;
            if (\is_string($rawName) && $rawName !== '' && \str_contains($rawName, '/')) {
                /** @var non-empty-string $packageName */
                $packageName = $rawName;
            }

            /** @var mixed $extra */
            $extra = $decoded['extra'] ?? null;
        }

        // Composer-shaped donor: hand the name + raw extra back for the
        // caller to run through the mapper.
        if ($packageName !== null && VendorConfigMapper::declaresSkills($extra)) {
            return DonorArchiveInspection::composerShaped($packageName, $extra);
        }

        // Auto-discovery fallback: no usable manifest, but the archive
        // may still ship SKILL.md files at conventional or nested roots.
        $discovered = $this->scanner->scan($extractedRoot);
        if ($discovered === []) {
            return DonorArchiveInspection::rejected(DonorArchiveRejection::NoDonorShape);
        }

        $synthesisedName = $packageName ?? $packageHint;
        if ($synthesisedName === null) {
            return DonorArchiveInspection::rejected(DonorArchiveRejection::NoPackageName);
        }

        return DonorArchiveInspection::bareSkillRepo(
            $synthesisedName,
            $discovered[0]->container,
            \array_map(static fn(DiscoveredSkill $skill): Path => $skill->dir, $discovered),
        );
    }
}
