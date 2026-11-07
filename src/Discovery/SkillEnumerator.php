<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use Internal\Path;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Filesystem\PathContainment;

/**
 * Looks inside each donor's source directory and lists the immediate
 * subdirectories as {@see Skill} candidates.
 *
 * One subdir → one skill. For each skill the enumerator also reads the
 * `SKILL.md` frontmatter `name:` field so the `--skill` allowlist can
 * match the canonical name (e.g. `symfony:quality-checks`) instead of
 * the bare directory (`quality-checks`). When no frontmatter is
 * present, the directory name is used as the canonical identity, so
 * older donors that don't ship a manifest keep working.
 *
 * Loose files at the source root (e.g. `README.md`) are ignored — they
 * are not skills.
 *
 * Failures are soft: a missing or unreadable `source` directory becomes
 * a warning and that donor is dropped from the result. The caller (or
 * `-v` verbosity) decides whether to print the warning.
 *
 * A donor whose tree contains the sync target, such as the project itself
 * registered as a `dir` source, has no skills in the target: what sits there
 * are installed copies, whichever donor they came from. Discovery already
 * leaves them out of the skill directories it finds; a declared `source` is
 * listed here, so a candidate within the target is dropped here too.
 */
final readonly class SkillEnumerator
{
    private const SAFE_SKILL_NAME = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    /**
     * Longest skill name the Agent Skills specification allows.
     */
    private const MAX_NAME_LENGTH = 64;

    /**
     * Windows device names, which cannot name a directory there even with an
     * extension, and also not with spaces before it (`nul .md`).
     */
    private const RESERVED_DEVICE_NAME = '/^(?:con|prn|aux|nul|conin\$|conout\$|com[0-9¹²³]|lpt[0-9¹²³]) *(?:\..*)?$/iuD';

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private SkillFrontmatterReader $frontmatterReader = new SkillFrontmatterReader(),
    ) {}

    /**
     * Why a skill directory name is never installed, or `null` when it may be.
     * Discovery calls this too, so a skill it counts is one that gets installed.
     * The name must stay one visible directory under the same spelling on every
     * supported file system, since a donor on one may sync to a target on another:
     *
     * - A hidden directory in the target is never a skill to an agent, and
     *   `.git` there would hand the donor a repository config git obeys.
     * - A name shaped like a Windows short name can resolve to another skill's
     *   directory.
     * - Windows strips a trailing dot or space, so `foo.` would land in another
     *   skill's `foo`; it rejects device names and some characters outright,
     *   which would abort the sync halfway. A regex error counts as a refusal.
     *
     * @psalm-pure
     */
    public static function refusedDirectoryName(string $name): ?string
    {
        return match (true) {
            $name === '' || $name[0] === '.' => 'is hidden',
            self::looksLikeShortName($name) =>
                'looks like a Windows short name, which can resolve to another skill\'s directory',
            \preg_match('//u', $name) !== 1 => 'is not valid UTF-8',
            \preg_match('/[\x00-\x1f\/\\\\:<>"|?*]/', $name) !== 0 =>
                'holds a character Windows does not allow in a directory name',
            \in_array($name[-1], ['.', ' '], true) => 'ends in a dot or space, which Windows strips',
            \preg_match(self::RESERVED_DEVICE_NAME, $name) !== 0 => 'is a Windows device name',
            default => null,
        };
    }

    /**
     * @param list<VendorConfig> $donors
     * @param Path|null $target the sync target, left out of each donor that contains it; `null` when
     *        no target is known
     */
    public function enumerate(array $donors, ?Path $target = null): SkillEnumerationResult
    {
        $skills = [];
        $warnings = [];
        $skipped = [];
        $renamed = [];
        $droppedDonors = [];
        /** @var array<non-empty-string, non-empty-list<string>> $dropReasons */
        $dropReasons = [];

        // Allowlist bookkeeping spans donor rows: a multi-source donor
        // arrives as several rows sharing one package name, and a
        // declared skill living in any of its source directories must
        // not be reported missing by the sibling rows. Keyed
        // package → declared name → seen anywhere.
        /** @var array<non-empty-string, array<string, bool>> $allowlistSeen */
        $allowlistSeen = [];
        /** @var array<non-empty-string, true> $installingPackages */
        $installingPackages = [];
        /** @var array<non-empty-string, true> $refusingPackages */
        $refusingPackages = [];
        /** @var array<non-empty-string, true> $targetHolders packages with a row that holds the target */
        $targetHolders = [];

        foreach ($donors as $donor) {
            $skippedBefore = \count($skipped);

            // Auto-discovered donors carry their skill directories explicitly
            // (they may sit at a catalog depth the immediate-subdir scan below
            // cannot reach); declared donors are scanned from their `source`.
            if ($donor->discoveredSkillDirs !== null) {
                $candidates = $this->discoveredCandidates($donor, $skipped);
            } else {
                $sourcePath = (string) $donor->sourcePath();

                if (!\is_dir($sourcePath)) {
                    $reason = \sprintf('source directory "%s" does not exist', $donor->source);
                    $warnings[] = $donor->packageName . ': ' . $reason;
                    $droppedDonors[] = $donor->packageName;
                    $dropReasons[$donor->packageName][] = $reason;
                    continue;
                }

                $entries = \scandir($sourcePath);
                if ($entries === false) {
                    $reason = \sprintf('source directory "%s" is unreadable', $donor->source);
                    $warnings[] = $donor->packageName . ': ' . $reason;
                    $droppedDonors[] = $donor->packageName;
                    $dropReasons[$donor->packageName][] = $reason;
                    continue;
                }

                $candidates = $this->declaredCandidates($sourcePath, $entries);
            }

            // Optional per-donor allowlist. Tracks which declared names
            // we actually saw so the "declared but missing" warning can
            // call them out without false positives from typos elsewhere.
            $filter = $donor->skillFilter;
            if ($filter !== null) {
                foreach ($filter as $declared) {
                    $allowlistSeen[$donor->packageName][$declared] ??= false;
                }
            }
            $filterSet = $filter === null ? null : \array_fill_keys($filter, true);
            $excludedTarget = $target !== null && self::containsTarget($donor, $target) ? $target : null;
            if ($excludedTarget !== null) {
                $targetHolders[$donor->packageName] = true;
            }

            foreach ($candidates as [$entry, $skillPath, $rename]) {
                // Resolved, not spelled: an alias such as `.claude/skills` links
                // into the target, and its entries are installed copies too.
                if ($excludedTarget !== null && PathContainment::isWithin(Path::create($skillPath), $excludedTarget)) {
                    continue;
                }

                // A renamed root skill is known by the name it is installed
                // under: its frontmatter name was rejected.
                $canonicalName = $rename === null
                    ? $this->readCanonicalName(Path::create($skillPath), $entry)
                    : $entry;

                if ($filterSet !== null) {
                    if (!\array_key_exists($canonicalName, $filterSet)) {
                        // Skill exists in the donor but is not on the
                        // user's allowlist — drop it silently. The
                        // "skipped because filtered" case is the whole
                        // point of the field; warning every time would
                        // drown out the legitimate diagnostics.
                        continue;
                    }
                    $allowlistSeen[$donor->packageName][$canonicalName] = true;
                }

                $refusal = self::refusedDirectoryName($entry);
                if ($refusal !== null) {
                    // Reported only for a directory that is a skill: a declared
                    // source may hold `.github` or other plain directories.
                    if (SkillTreeScanner::holdsSkillMd(Path::create($skillPath))) {
                        $skipped[] = new SkillNotice(
                            $donor->packageName,
                            \sprintf('skill directory "%s" %s, and is not installed', $entry, $refusal),
                        );
                    }
                    continue;
                }

                if ($rename !== null) {
                    $renamed[] = $rename;
                }
                $skills[] = new Skill(
                    name: $entry,
                    canonicalName: $canonicalName,
                    sourceDir: Path::create($skillPath),
                    packageName: $donor->packageName,
                );
                $installingPackages[$donor->packageName] = true;
            }

            if (\count($skipped) > $skippedBefore) {
                $refusingPackages[$donor->packageName] = true;
            }
        }

        // A donor whose skills were all refused for their names contributes
        // nothing, like one with a missing source: a full `--clean` must not
        // delete what it installed before. Judged per package, since a
        // multi-source donor's rows install for one another.
        foreach ($refusingPackages as $packageName => $_) {
            if (!isset($installingPackages[$packageName])) {
                $droppedDonors[] = $packageName;
            }
        }

        // Allowlist names that never matched a directory in any of the
        // donor's rows are most likely typos or stale entries. Surface
        // them as `-v` warnings without aborting — the rest of the
        // allowlist still syncs.
        foreach ($allowlistSeen as $packageName => $names) {
            $missing = [];
            foreach ($names as $name => $seen) {
                if (!$seen) {
                    // A copy of the skill in the target would otherwise make
                    // the warning read as a contradiction.
                    $copied = isset($targetHolders[$packageName]) && $target !== null
                        && \is_dir((string) $target->join($name));
                    $missing[] = \sprintf(
                        'skill "%s" declared in the skill allowlist but not found in the donor%s',
                        $name,
                        $copied ? \sprintf('; its copy installed in the target %s does not count', $target) : '',
                    );
                }
            }
            foreach ($missing as $reason) {
                $warnings[] = $packageName . ': ' . $reason;
            }
            // A stale allowlist, say one naming the catalog skills of a donor
            // that is now one root skill, selects nothing and so drops the donor.
            // Judged per package: a multi-source donor's rows share one allowlist.
            if (!isset($installingPackages[$packageName]) && $missing !== []) {
                $droppedDonors[] = $packageName;
                $dropReasons[$packageName] = [...$dropReasons[$packageName] ?? [], ...$missing];
            }
        }

        return new SkillEnumerationResult(
            skills: $skills,
            warnings: $warnings,
            droppedDonors: \array_values(\array_unique($droppedDonors)),
            dropReasons: $dropReasons,
            skipped: $skipped,
            renamed: $renamed,
        );
    }

    /**
     * `true` when the target lies below the directory the donor is scanned
     * from. A donor that is the target or lies within it keeps its skills
     * there: they are sources, which the sync keeps and never copies onto
     * themselves.
     */
    private static function containsTarget(VendorConfig $donor, Path $target): bool
    {
        $scanRoot = $donor->discoveredSkillDirs === null ? $donor->sourcePath() : $donor->packageRoot;

        return PathContainment::isBelow($target, $scanRoot);
    }

    /**
     * @psalm-pure
     */
    private static function nameRule(): string
    {
        return \sprintf(
            'is lowercase and hyphenated, at most %d characters, and not a Windows device name such as `con`',
            self::MAX_NAME_LENGTH,
        );
    }

    /**
     * `true` for a name shaped like a Windows 8.3 short name (`SKILLS~1`). NTFS
     * gives long directory names such an alias, so writing to it can land in
     * another skill's directory without any name collision being visible.
     *
     * @psalm-pure
     */
    private static function looksLikeShortName(string $name): bool
    {
        return \preg_match('/~\d/', $name) !== 0;
    }

    /**
     * A name a root skill may take: one {@see self::refusedDirectoryName()}
     * accepts, within the length limit counted in characters.
     *
     * @psalm-assert-if-true non-empty-string $name
     *
     * @psalm-pure
     */
    private static function isPortableDirName(string $name): bool
    {
        return self::refusedDirectoryName($name) === null
            && (int) \preg_match_all('/./su', $name) <= self::MAX_NAME_LENGTH;
    }

    /**
     * Immediate subdirectories of a declared donor's `source`. Loose files at
     * the source root (e.g. `README.md`) are ignored — they are not skills.
     *
     * @param list<string> $entries result of {@see \scandir()} on `$sourcePath`
     *
     * @return list<array{non-empty-string, non-empty-string, null}> `[directory name, absolute path, no rename]`
     */
    private function declaredCandidates(string $sourcePath, array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $skillPath = $sourcePath . \DIRECTORY_SEPARATOR . $entry;
            if (!\is_dir($skillPath)) {
                continue;
            }
            /** @var non-empty-string $entry */
            $out[] = [$entry, $skillPath, null];
        }

        return $out;
    }

    /**
     * Candidates for an auto-discovered donor: the exact skill directories the
     * scanner found. Each is re-validated (still a directory holding a
     * `SKILL.md`) so a tree that changed between discovery and enumeration
     * degrades quietly instead of producing a phantom skill.
     *
     * @param list<SkillNotice> $skipped appended to when a root skill has no usable target name
     *
     * @return list<array{non-empty-string, non-empty-string, SkillNotice|null}> `[target directory name,
     *         absolute path, the rename to report once the skill is installed]`
     *
     * @param-out list<SkillNotice> $skipped
     */
    private function discoveredCandidates(VendorConfig $donor, array &$skipped): array
    {
        $out = [];
        foreach ($donor->discoveredSkillDirs ?? [] as $dir) {
            $skillPath = (string) $dir;
            if (!\is_dir($skillPath) || !SkillTreeScanner::holdsSkillMd($dir)) {
                continue;
            }
            if (!$donor->rootSkill) {
                $out[] = [$dir->name(), $skillPath, null];
                continue;
            }

            $named = $this->rootSkillName($donor, $dir, $skipped);
            if ($named !== null) {
                $out[] = [$named[0], $skillPath, $named[1]];
            }
        }

        return $out;
    }

    /**
     * Target directory name for a donor whose root is the skill: the frontmatter
     * `name` when it is a lowercase, hyphenated name, otherwise the last segment
     * of the package name. Both come from the donor, so either one is used only
     * when it stays a single directory inside the target on every platform.
     *
     * @param list<SkillNotice> $skipped appended to when neither name is usable
     *
     * @return array{non-empty-string, SkillNotice|null}|null the name, with a notice when the frontmatter
     *         name was replaced; `null` when neither name is usable
     *
     * @param-out list<SkillNotice> $skipped
     */
    private function rootSkillName(VendorConfig $donor, Path $skillDir, array &$skipped): ?array
    {
        $declared = $this->frontmatterReader->read($skillDir)['name'] ?? null;
        if (
            \is_string($declared)
            && \preg_match(self::SAFE_SKILL_NAME, $declared) === 1
            && self::isPortableDirName($declared)
        ) {
            return [$declared, null];
        }

        $slashAt = \strrpos($donor->packageName, '/');
        $fallback = $slashAt === false ? $donor->packageName : \substr($donor->packageName, $slashAt + 1);
        if (!self::isPortableDirName($fallback)) {
            $skipped[] = new SkillNotice(
                $donor->packageName,
                \sprintf('the root SKILL.md has no usable name; set a `name` in its frontmatter that %s', self::nameRule()),
            );
            return null;
        }

        if (!\is_string($declared) || $declared === '') {
            return [$fallback, null];
        }

        return [$fallback, new SkillNotice(
            $donor->packageName,
            \sprintf(
                'the root SKILL.md name "%s" cannot be a directory name, which %s; installing it as "%s"',
                $declared,
                self::nameRule(),
                $fallback,
            ),
        )];
    }

    /**
     * Resolve the skill's canonical name from `SKILL.md` frontmatter.
     * Falls back to the directory name when the file is missing, has
     * no parseable frontmatter, or its frontmatter doesn't carry a
     * non-empty `name:` field — same behaviour pre-existing skills
     * relied on.
     *
     * @param non-empty-string $directoryName
     *
     * @return non-empty-string
     */
    private function readCanonicalName(Path $skillDir, string $directoryName): string
    {
        $frontmatter = $this->frontmatterReader->read($skillDir);
        if ($frontmatter === null) {
            return $directoryName;
        }
        $name = $frontmatter['name'] ?? null;
        if (!\is_string($name) || $name === '') {
            return $directoryName;
        }
        return $name;
    }
}
