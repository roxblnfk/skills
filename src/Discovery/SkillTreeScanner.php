<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use Internal\Path;
use LLM\Skills\Filesystem\LinkGuard;

/**
 * Locates skill directories inside a package that does **not** declare
 * `extra.skills`, by looking for `SKILL.md` files rather than assuming a single
 * fixed `skills/` root.
 *
 * Used by {@see DonorDiscovery} for the local Composer auto-discovery path. The
 * algorithm mirrors the `vercel-labs/skills` (`npx skills`) tool:
 *
 * 1. **Well-known containers.** Probe a fixed list of conventional roots
 *    ({@see self::CONTAINER_ROOTS}). Inside each existing container, accept a
 *    `SKILL.md` at depth one (flat `<container>/<name>/`) or depth two (catalog
 *    `<container>/<category>/<name>/`).
 * 2. **Shadowing / no nesting.** Once a directory is recognised as a skill (it
 *    holds a `SKILL.md`) the scanner does not descend into it — a skill cannot
 *    contain a nested skill, and a skill's auxiliary files are never mistaken
 *    for skills.
 * 3. **Fallback recursion.** Only when *no* container yielded a skill, walk the
 *    package tree (bounded by {@see self::FALLBACK_MAX_DEPTH}, skipping
 *    {@see self::SKIP_DIRS}) to find `SKILL.md` files in non-conventional
 *    locations.
 *
 * Junction safety: the scan never traverses a symlinked or junctioned
 * subdirectory ({@see self::immediateSubdirs()}), so a linked subtree cannot
 * be discovered as skills and a link cycle cannot outlast the depth ceiling.
 * As a second line, every accepted skill directory must still resolve (via
 * {@see \realpath()}) to a path contained within the package root; anything
 * escaping the boundary is silently rejected.
 */
final readonly class SkillTreeScanner
{
    /**
     * Conventional skill-root directories, relative to the package install
     * path, probed in order. The list is intentionally conservative; a package
     * shipping skills elsewhere is still caught by the fallback recursion.
     *
     * @var list<non-empty-string>
     */
    public const CONTAINER_ROOTS = [
        '.agents/skills',
        '.claude/skills',
        '.cursor/skills',
        'skills',
        'resources/skills',
    ];

    /**
     * Directory names never descended into during the fallback recursion —
     * dependency trees and VCS metadata never carry first-party skills and
     * scanning them is pure cost (and a prompt-injection surface).
     *
     * @var list<non-empty-string>
     */
    public const SKIP_DIRS = ['vendor', 'node_modules', '.git'];

    /**
     * Depth ceiling for the fallback recursion, counted in levels below the
     * package root. Keeps a pathological tree from turning discovery into a
     * full-disk walk.
     */
    public const FALLBACK_MAX_DEPTH = 5;

    /**
     * @return list<DiscoveredSkill>
     */
    public function scan(Path $packageRoot): array
    {
        $rootReal = \realpath((string) $packageRoot);
        if ($rootReal === false || $rootReal === '') {
            return [];
        }

        /** @var array<string, DiscoveredSkill> $found keyed by the skill dir's realpath to dedupe */
        $found = [];

        foreach (self::CONTAINER_ROOTS as $container) {
            $containerDir = $packageRoot->join($container);
            if (!\is_dir((string) $containerDir)) {
                continue;
            }

            foreach ($this->immediateSubdirs((string) $containerDir) as $name) {
                $skillDir = $containerDir->join($name);

                if ($this->hasSkillMd($skillDir)) {
                    // Flat layout: <container>/<name>/SKILL.md. Found a skill —
                    // do not descend (shadowing).
                    $this->record($found, $rootReal, $skillDir, $name, $container);
                    continue;
                }

                // Catalog layout: <container>/<category>/<name>/SKILL.md.
                foreach ($this->immediateSubdirs((string) $skillDir) as $leaf) {
                    $leafDir = $skillDir->join($leaf);
                    if ($this->hasSkillMd($leafDir)) {
                        $this->record($found, $rootReal, $leafDir, $leaf, $container . '/' . $name);
                    }
                }
            }
        }

        if ($found === []) {
            // Nothing in the conventional spots — cast a wider, bounded net.
            $this->scanFallback($packageRoot, $rootReal, '', 0, $found);
        }

        return \array_values($found);
    }

    /**
     * Bounded recursive walk used only when the well-known containers came up
     * empty. Finds the first `SKILL.md` along each branch and stops descending
     * there (shadowing); skips dependency/VCS directories outright.
     *
     * @param non-empty-string $rootReal realpath of the package root
     * @param string $relPrefix relative path (from the package root) of `$dir`; `''` at the root
     * @param int $depth levels below the package root for `$dir`
     * @param array<string, DiscoveredSkill> $found
     *
     * @param-out array<string, DiscoveredSkill> $found
     */
    private function scanFallback(
        Path $dir,
        string $rootReal,
        string $relPrefix,
        int $depth,
        array &$found,
    ): void {
        if ($depth >= self::FALLBACK_MAX_DEPTH) {
            return;
        }

        foreach ($this->immediateSubdirs((string) $dir) as $name) {
            // Dependency/VCS trees and hidden directories never carry
            // first-party skills worth a deep walk.
            if (\in_array($name, self::SKIP_DIRS, true) || $name[0] === '.') {
                continue;
            }

            $child = $dir->join($name);

            // A nested package (its own composer.json) is a separate unit of
            // distribution — its skills belong to *it*, not to the package we
            // are scanning. Never descend across that boundary.
            if (\is_file((string) $child->join('composer.json'))) {
                continue;
            }

            if ($this->hasSkillMd($child)) {
                // A skill's parent is the container we group it under; the root
                // itself surfaces as "." since a donor source must be non-empty.
                $container = $relPrefix === '' ? '.' : $relPrefix;
                $this->record($found, $rootReal, $child, $name, $container);
                continue;
            }

            $childPrefix = $relPrefix === '' ? $name : $relPrefix . '/' . $name;
            $this->scanFallback($child, $rootReal, $childPrefix, $depth + 1, $found);
        }
    }

    /**
     * Validate containment and append a discovered skill, deduping by realpath.
     *
     * @param array<string, DiscoveredSkill> $found
     * @param non-empty-string $rootReal
     * @param non-empty-string $name
     * @param non-empty-string $container
     *
     * @param-out array<string, DiscoveredSkill> $found
     */
    private function record(
        array &$found,
        string $rootReal,
        Path $skillDir,
        string $name,
        string $container,
    ): void {
        $real = \realpath((string) $skillDir);
        if ($real === false || !$this->within($rootReal, $real)) {
            return;
        }

        $found[$real] ??= new DiscoveredSkill(dir: $skillDir, name: $name, container: $container);
    }

    /**
     * @return list<non-empty-string> immediate subdirectory names (no `.`/`..`)
     */
    private function immediateSubdirs(string $dir): array
    {
        $entries = \scandir($dir);
        if ($entries === false) {
            return [];
        }

        $out = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $dir . \DIRECTORY_SEPARATOR . $entry;
            // A symlink or junction is not first-party package content and
            // could point anywhere; never descend through one, and never let
            // a link cycle defeat the recursion's depth ceiling.
            if (LinkGuard::isLink($child)) {
                continue;
            }
            if (\is_dir($child)) {
                /** @var non-empty-string $entry */
                $out[] = $entry;
            }
        }

        return $out;
    }

    private function hasSkillMd(Path $dir): bool
    {
        return \is_file((string) $dir->join('SKILL.md'));
    }

    /**
     * `true` when `$candidateReal` is the root itself or sits inside it.
     *
     * @param non-empty-string $rootReal
     *
     * @psalm-pure
     */
    private function within(string $rootReal, string $candidateReal): bool
    {
        if ($candidateReal === $rootReal) {
            return true;
        }

        $rootPrefix = \rtrim($rootReal, '/\\') . \DIRECTORY_SEPARATOR;

        return \str_starts_with($candidateReal, $rootPrefix);
    }
}
