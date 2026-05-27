<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use Internal\Path;
use LLM\Skills\Config\VendorConfig;

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
 */
final readonly class SkillEnumerator
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private SkillFrontmatterReader $frontmatterReader = new SkillFrontmatterReader(),
    ) {}

    /**
     * @param list<VendorConfig> $donors
     */
    public function enumerate(array $donors): SkillEnumerationResult
    {
        $skills = [];
        $warnings = [];

        foreach ($donors as $donor) {
            $sourcePath = (string) $donor->sourcePath();

            if (!\is_dir($sourcePath)) {
                $warnings[] = \sprintf(
                    '%s: source directory "%s" does not exist',
                    $donor->packageName,
                    $donor->source,
                );
                continue;
            }

            $entries = \scandir($sourcePath);
            if ($entries === false) {
                $warnings[] = \sprintf(
                    '%s: source directory "%s" is unreadable',
                    $donor->packageName,
                    $donor->source,
                );
                continue;
            }

            // Optional per-donor allowlist. Tracks which declared names
            // we actually saw so the "declared but missing" warning can
            // call them out without false positives from typos elsewhere.
            $filter = $donor->skillFilter;
            $filterSet = $filter === null ? null : \array_fill_keys($filter, false);

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $skillPath = $sourcePath . \DIRECTORY_SEPARATOR . $entry;
                if (!\is_dir($skillPath)) {
                    // Files at the source root are not skills. Ignore silently.
                    continue;
                }

                /** @var non-empty-string $entry */
                $canonicalName = $this->readCanonicalName(Path::create($skillPath), $entry);

                if ($filterSet !== null) {
                    if (!\array_key_exists($canonicalName, $filterSet)) {
                        // Skill exists in the donor but is not on the
                        // user's allowlist — drop it silently. The
                        // "skipped because filtered" case is the whole
                        // point of the field; warning every time would
                        // drown out the legitimate diagnostics.
                        continue;
                    }
                    $filterSet[$canonicalName] = true;
                }

                $skills[] = new Skill(
                    name: $entry,
                    canonicalName: $canonicalName,
                    sourceDir: Path::create($skillPath),
                    packageName: $donor->packageName,
                );
            }

            // Allowlist names that never matched any directory in the
            // donor are most likely typos or stale entries. Surface them
            // as `-v` warnings without aborting — the rest of the
            // allowlist still syncs.
            if ($filterSet !== null) {
                foreach ($filterSet as $name => $seen) {
                    if (!$seen) {
                        $warnings[] = \sprintf(
                            '%s: skill "%s" declared in the skill allowlist but not found in the donor',
                            $donor->packageName,
                            $name,
                        );
                    }
                }
            }
        }

        return new SkillEnumerationResult(skills: $skills, warnings: $warnings);
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
