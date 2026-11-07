<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Source;

use Internal\Path;
use LLM\Skills\Config\Mapper\VendorConfigMapper;
use LLM\Skills\Discovery\DiscoveredSkill;
use LLM\Skills\Discovery\SkillEnumerator;
use LLM\Skills\Discovery\SkillTreeScanner;
use LLM\Skills\Filesystem\PathContainment;
use LLM\Skills\Sync\SkillConflict;

/**
 * Classifies an extracted archive as a skill donor — the single
 * source of truth shared by the two code paths that fetch a remote
 * archive and then have to decide what it is:
 *
 * - {@see \LLM\Skills\Add\AddRunner} validates the archive during
 *   `skills:add` (it only needs the donor's package name to scope the
 *   follow-up sync).
 * - {@see SourceProvider} does the same during `skills:update`, then
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
 *    conventional roots first, then recurses). A `SKILL.md` at the
 *    archive root makes the whole archive one skill unless the scan
 *    finds a skill at most two levels down. The name falls back to the
 *    adapter-side `$packageHint`.
 *
 * Anything else is a {@see DonorArchiveRejection}.
 */
final readonly class DonorArchiveInspector
{
    /**
     * Deepest level below the archive root a skill directory may sit at and
     * still turn an archive with a root SKILL.md into a catalog. Deeper ones,
     * such as `resources/skills/<name>/`, are that root skill's content.
     */
    private const OUTRANKING_DEPTH = 2;

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
        // A shallow skill wins over a root SKILL.md, which a catalog may ship
        // as its index. Shallow skills are looked for apart from the scan, whose
        // containers come first: an installed copy in `.claude/skills/` must not
        // hide a catalog's `pdf/`. Otherwise a root SKILL.md makes the whole
        // archive one skill, and SKILL.md files below it are its content,
        // including skills installed into a target that lies inside the donor.
        $discovered = $this->scanner->scan($extractedRoot);
        $rootIsSkill = false;
        if (SkillTreeScanner::holdsSkillMd($extractedRoot)) {
            $shallow = self::withoutIndexes(
                $this->scanner->scanShallow($extractedRoot, self::OUTRANKING_DEPTH),
                $discovered,
            );
            // Decided before names are merged: a shallow skill outranks the
            // root even when a deeper one of its name is what gets installed.
            $rootIsSkill = !self::anyOutranksTheRoot([...$discovered, ...$shallow]);
            $discovered = self::merge($discovered, $shallow);
        }
        if (!$rootIsSkill && $discovered === []) {
            return DonorArchiveInspection::rejected(DonorArchiveRejection::NoDonorShape);
        }

        $synthesisedName = $packageName ?? $packageHint;
        if ($synthesisedName === null) {
            return DonorArchiveInspection::rejected(DonorArchiveRejection::NoPackageName);
        }

        if ($rootIsSkill) {
            return DonorArchiveInspection::rootSkill($synthesisedName, $extractedRoot);
        }

        return DonorArchiveInspection::bareSkillRepo(
            $synthesisedName,
            $discovered[0]->container,
            \array_map(static fn(DiscoveredSkill $skill): Path => $skill->dir, $discovered),
        );
    }

    /**
     * @param list<DiscoveredSkill> $first
     * @param list<DiscoveredSkill> $second
     *
     * Two skills of one donor under one name would abort the sync, so names
     * decide what joins, not paths, which the two scans may spell differently:
     * a skill of `$second` joins only under a name no visible skill of `$first`
     * holds, and a skill in a hidden directory, such as an installed copy in a
     * committed `.claude/skills/`, gives way to a visible one of its name and to
     * an earlier hidden one.
     *
     * @return list<DiscoveredSkill>
     *
     * @psalm-pure
     */
    private static function merge(array $first, array $second): array
    {
        $visible = [];
        foreach ($first as $skill) {
            if (!self::inHiddenDirectory($skill)) {
                $visible[SkillConflict::collisionKey($skill->name)] = true;
            }
        }
        // Two visible namesakes of `$second` both join and so conflict, as they
        // do in a catalog without a root SKILL.md, instead of one going unseen.
        $taken = $visible;
        foreach ($second as $skill) {
            $key = SkillConflict::collisionKey($skill->name);
            if (!isset($taken[$key])) {
                $first[] = $skill;
                $visible[$key] = true;
            }
        }

        $out = [];
        $hidden = [];
        foreach ($first as $skill) {
            $key = SkillConflict::collisionKey($skill->name);
            if (self::inHiddenDirectory($skill)) {
                if (isset($visible[$key]) || isset($hidden[$key])) {
                    continue;
                }
                $hidden[$key] = true;
            }
            $out[] = $skill;
        }

        return $out;
    }

    /**
     * Drop each shallow skill whose directory holds skills the scan found, such
     * as a `skills/SKILL.md` index above `skills/pdf/`: it is no skill of its
     * own, and installing it would copy every skill below it a second time.
     *
     * @param list<DiscoveredSkill> $shallow
     * @param list<DiscoveredSkill> $discovered
     *
     * @return list<DiscoveredSkill>
     */
    private static function withoutIndexes(array $shallow, array $discovered): array
    {
        return \array_values(\array_filter(
            $shallow,
            static function (DiscoveredSkill $candidate) use ($discovered): bool {
                foreach ($discovered as $skill) {
                    if (
                        PathContainment::isWithin($skill->dir, $candidate->dir)
                        && !PathContainment::isSame($skill->dir, $candidate->dir)
                    ) {
                        return false;
                    }
                }
                return true;
            },
        ));
    }

    /**
     * `true` when any directory between the archive root and the skill is
     * hidden, such as `.claude/skills` or `skills/.archive`.
     *
     * @psalm-pure
     */
    private static function inHiddenDirectory(DiscoveredSkill $skill): bool
    {
        return $skill->container !== '.' && \preg_match('#(?:\A|/)\.#', $skill->container) === 1;
    }

    /**
     * `true` when a discovered skill makes the archive a catalog despite its
     * root SKILL.md. Only a skill the sync installs counts, at most
     * {@see self::OUTRANKING_DEPTH} levels below the root (`pdf/`, `skills/pdf/`)
     * and outside the hidden directories agents install skills into, which may
     * hold this very skill's installed copy.
     *
     * @param list<DiscoveredSkill> $discovered
     *
     * @psalm-pure
     */
    private static function anyOutranksTheRoot(array $discovered): bool
    {
        foreach ($discovered as $skill) {
            if (self::inHiddenDirectory($skill) || SkillEnumerator::refusedDirectoryName($skill->name) !== null) {
                continue;
            }
            $depth = $skill->container === '.' ? 1 : \substr_count($skill->container, '/') + 2;
            if ($depth <= self::OUTRANKING_DEPTH) {
                return true;
            }
        }

        return false;
    }
}
