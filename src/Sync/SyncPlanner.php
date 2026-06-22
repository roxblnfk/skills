<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Config\TrustedVendors;
use LLM\Skills\Config\VendorConfig;

/**
 * Pure planning step between Composer discovery and {@see SyncEngine}.
 *
 * Inputs: the already-mapped list of donor packages, plus the three config
 * objects ({@see ProjectConfig}, {@see SyncOptions}, builtin trust list) and
 * the project root path. The planner does not touch the filesystem or
 * Composer's API — that work is the command's responsibility.
 *
 * Outputs: a {@see SyncPlan} that partitions donors into "approved" (will be
 * synced) and "skipped — untrusted" (silently dropped, surfaced via the
 * trailing `[skip]` notice). The plan also resolves the absolute target
 * directory.
 *
 * Trust rules:
 *
 * - Naming a package as a positional argument is an implicit grant of trust:
 *   if the user types `composer skills:update acme/foo`, the planner treats
 *   `acme/foo` as approved regardless of the trust list. The trust list is
 *   the bouncer for *auto-discovered* donors, not for ones the user already
 *   asked for by name.
 * - A donor flagged {@see VendorConfig::$implicitTrust} is user-declared at
 *   the source level (today: every `remote[]` entry, regardless of `from`).
 *   The trust list applies to local-provider transitive discoveries only,
 *   so the planner skips it for these donors.
 * - A package declared as a direct dependency in the consumer's root
 *   `composer.json` (under `require` or `require-dev`) is implicitly
 *   trusted — the user already owns the decision to depend on it. This
 *   short-circuit is disabled when `extra.skills.trusted-replace` is
 *   `true`, since that flag asks for explicit-only trust.
 * - Without positional filters, every other donor must clear the effective
 *   trust list (built-in ∪ project ∪ `--trust` ∪ direct deps).
 */
final readonly class SyncPlanner
{
    /**
     * @param list<VendorConfig> $donors all donor packages successfully mapped from Composer
     * @param list<non-empty-string> $directDependencies package names declared in the consumer's
     *         root `require` and `require-dev`. Implicitly trusted unless
     *         {@see ProjectConfig::$trustedReplace} is `true`.
     */
    public function plan(
        array $donors,
        ProjectConfig $project,
        SyncOptions $options,
        TrustedVendors $builtin,
        Path $projectRoot,
        array $directDependencies = [],
    ): SyncPlan {
        [$filtered, $filteredOut] = $this->partitionByFilter($donors, $options);

        $approved = [];
        $skipped = [];

        if ($options->hasPackageFilters()) {
            // Positional naming is an implicit trust grant — every donor that
            // survived the filter goes straight to approved without consulting
            // the trust list.
            $approved = $filtered;
        } else {
            $trust = $this->effectiveTrust($project, $options, $builtin);
            $directSet = $project->trustedReplace
                ? []
                : \array_fill_keys($directDependencies, true);
            foreach ($filtered as $donor) {
                // A donor flagged `implicitTrust` is user-declared
                // (today: every `remote[]` entry); the trust list
                // applies only to local-provider transitive discoveries,
                // so we skip the check entirely.
                if (
                    $donor->implicitTrust
                    || isset($directSet[$donor->packageName])
                    || $trust->trusts($donor->packageName)
                ) {
                    $approved[] = $donor;
                    continue;
                }

                // Auto-discovered + untrusted — silently dropped from sync;
                // the command surfaces a one-line notice so the user knows
                // skills were ignored.
                $skipped[] = $donor->packageName;
            }
        }

        // The root that `target` and aliases must stay inside. Defaults to
        // the project root; `path-from-root` re-anchors it to a verified
        // ancestor (e.g. a monorepo root) so writes can legitimately reach
        // a shared directory above the project.
        $root = $this->resolveContainmentRoot($project, $projectRoot);

        $rawTarget = $options->targetOverride ?? $project->target;
        $target = $this->resolveTarget($project, $options, $root);
        $this->assertNotProjectRoot($target, $root, 'target', $rawTarget);
        $this->assertWithinProject($target, $root, 'target', $rawTarget);

        return new SyncPlan(
            approvedDonors: $approved,
            skippedUntrustedNames: $skipped,
            target: $target,
            aliases: $this->resolveAliases($project, $options, $root, $target),
            filteredOutDonors: $filteredOut,
        );
    }

    /**
     * Literal path equality, honouring the platform's filesystem case
     * sensitivity (case-insensitive on Windows). Unlike {@see Path::match()}
     * this treats `*`, `?` and `[]` as ordinary characters, so paths whose
     * names contain glob metacharacters compare correctly.
     *
     * @psalm-pure
     */
    private static function pathsEqual(Path $a, Path $b): bool
    {
        return \DIRECTORY_SEPARATOR === '\\'
            ? \strcasecmp((string) $a, (string) $b) === 0
            : (string) $a === (string) $b;
    }

    /**
     * Whether `$resolved` is `$root` itself or a descendant of it. Walks up
     * `$resolved`'s parents comparing each with {@see self::pathsEqual()}, so
     * containment is literal (no glob), case-insensitive on Windows, and
     * works regardless of how {@see Path} renders the filesystem root.
     */
    private static function isWithin(Path $resolved, Path $root): bool
    {
        $current = $resolved;
        while (true) {
            if (self::pathsEqual($current, $root)) {
                return true;
            }
            $parent = $current->parent();
            if (self::pathsEqual($parent, $current)) {
                // Reached the filesystem root without meeting $root.
                return false;
            }
            $current = $parent;
        }
    }

    /**
     * Reject paths that resolve outside the project root.
     *
     * The project's own `composer.json` is trusted input (the user
     * wrote it), so this is not a sandbox boundary against malicious
     * actors — it's a footgun guard. A typo like `target: "../.."`
     * or a CLI argument like `--target=/etc/passwd` would otherwise
     * happily start writing files (or planting junctions) outside
     * the project. Donor packages are already pinned to their own
     * roots ({@see \LLM\Skills\Config\Mapper\VendorConfigMapper},
     * {@see \LLM\Skills\Discovery\SkillTreeScanner}); this check
     * brings the project side in line with that posture.
     *
     * Containment is checked literally (a path equal to the root, or one
     * whose string is prefixed by `root + separator`), not via a
     * {@see Path::match()} `root/*` glob: a project directory whose name
     * contains `*`, `?` or `[]` must be treated as an ordinary path, not a
     * wildcard. {@see Path} has already normalised separators and collapsed
     * `..`; {@see self::pathsEqual()} adds the Windows case-insensitivity.
     *
     * @param non-empty-string $context human-readable label of the config field, e.g. `target`
     *        or `alias[0]`; goes into the error message
     * @param non-empty-string $raw the user-supplied value, included verbatim in the error so
     *        the user can locate the offending entry in their config
     *
     * @throws MalformedProjectConfig
     */
    private function assertWithinProject(
        Path $resolved,
        Path $projectRoot,
        string $context,
        string $raw,
    ): void {
        if (self::isWithin($resolved, $projectRoot)) {
            return;
        }

        throw new MalformedProjectConfig(\sprintf(
            '%s "%s" resolves to "%s", which is outside the project root "%s"; '
            . '%s must stay inside the project',
            $context,
            $raw,
            $resolved,
            $projectRoot,
            $context,
        ));
    }

    /**
     * Reject a path that resolves to the root itself (rather than a child of
     * it). Equality is literal, not a {@see Path::match()} glob — a root
     * directory whose name contains `*`, `?` or `[]` must compare as itself,
     * not as a wildcard.
     *
     * @param non-empty-string $context human-readable label of the config field, e.g. `target`
     * @param non-empty-string $raw the user-supplied value, included verbatim in the error so
     *        the user can locate the offending entry in their config
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-pure
     */
    private function assertNotProjectRoot(
        Path $resolved,
        Path $projectRoot,
        string $context,
        string $raw,
    ): void {
        if (!self::pathsEqual($resolved, $projectRoot)) {
            return;
        }

        throw new MalformedProjectConfig(\sprintf(
            '%s "%s" resolves to the project root "%s"; %s must not be the project root itself',
            $context,
            $raw,
            $projectRoot,
            $context,
        ));
    }

    /**
     * @psalm-mutation-free
     */
    private function effectiveTrust(
        ProjectConfig $project,
        SyncOptions $options,
        TrustedVendors $builtin,
    ): TrustedVendors {
        $extras = new TrustedVendors($options->extraTrusted);

        return $project->trustedReplace
            ? $project->trusted->merge($extras)
            : $builtin->merge($project->trusted)->merge($extras);
    }

    /**
     * Split discovered donors into "kept" and "rejected by positional filter".
     *
     * Both halves are needed downstream: the kept half feeds trust resolution,
     * the rejected half is surfaced by `skills:show` under `Skipped:` so users
     * can see which donors a filter dropped without re-running without it.
     *
     * @param list<VendorConfig> $donors
     *
     * @return array{0: list<VendorConfig>, 1: list<VendorConfig>}
     *
     * @psalm-mutation-free
     */
    private function partitionByFilter(array $donors, SyncOptions $options): array
    {
        if (!$options->hasPackageFilters()) {
            return [$donors, []];
        }

        $kept = [];
        $rejected = [];
        foreach ($donors as $donor) {
            if ($options->matchesFilter($donor->packageName)) {
                $kept[] = $donor;
            } else {
                $rejected[] = $donor;
            }
        }

        return [$kept, $rejected];
    }

    /**
     * Resolve the directory that `target` and aliases must stay inside.
     *
     * Without `path-from-root` the containment root is the project root
     * (`getcwd()`). With it, the project declares its own location below
     * an intended outer root
     * (e.g. `packages/api` under a monorepo root): the planner climbs
     * that many parents and then verifies — via {@see Path::match()}, so
     * separator and Windows-case normalisation match the containment
     * check — that the descent reconstructs the real project location.
     * A stale or wrong declaration therefore fails loudly instead of
     * silently anchoring writes to an unintended ancestor.
     *
     * @throws MalformedProjectConfig
     */
    private function resolveContainmentRoot(ProjectConfig $project, Path $projectRoot): Path
    {
        $suffix = $project->pathFromRoot;
        if ($suffix === null) {
            return $projectRoot;
        }

        $depth = \count(\preg_split('#[/\\\\]#', $suffix) ?: []);
        $root = $projectRoot;
        for ($i = 0; $i < $depth; $i++) {
            $root = $root->parent();
        }

        if (!self::pathsEqual($projectRoot, $root->join($suffix))) {
            throw new MalformedProjectConfig(\sprintf(
                'path-from-root "%s" does not match the project location "%s"; '
                . 'the project directory must end with that suffix',
                $suffix,
                $projectRoot,
            ));
        }

        return $root;
    }

    private function resolveTarget(
        ProjectConfig $project,
        SyncOptions $options,
        Path $root,
    ): Path {
        /** @var non-empty-string $raw */
        $raw = $options->targetOverride ?? $project->target;

        return $this->resolvePath($raw, $root);
    }

    /**
     * Resolve every alias entry to an absolute {@see Path}. The CLI list,
     * when present, replaces the project's aliases entirely — passing any
     * `--alias` is an explicit takeover, matching `--target` semantics.
     *
     * Post-resolution checks: aliases must not match the resolved target
     * and must not collide with each other. These mirror the lexical
     * checks the mapper already runs against the raw config, but they
     * have to run again here because the CLI input is unstructured and
     * because relative paths could collapse to the same absolute path
     * even when their raw strings differ.
     *
     * @return list<Path>
     *
     * @throws MalformedProjectConfig
     */
    private function resolveAliases(
        ProjectConfig $project,
        SyncOptions $options,
        Path $root,
        Path $target,
    ): array {
        $raw = $options->aliasOverrides ?? $project->aliases;
        if ($raw === []) {
            return [];
        }

        $targetStr = (string) $target;

        $out = [];
        $seen = [];
        foreach ($raw as $index => $entry) {
            $resolved = $this->resolvePath($entry, $root);
            $context = \sprintf('alias[%d]', $index);
            $this->assertNotProjectRoot($resolved, $root, $context, $entry);
            $this->assertWithinProject($resolved, $root, $context, $entry);
            $resolvedStr = (string) $resolved;

            if ($resolvedStr === $targetStr) {
                throw new MalformedProjectConfig(\sprintf(
                    'alias "%s" resolves to the target path %s; an alias cannot point at itself',
                    $entry,
                    $targetStr,
                ));
            }
            if (isset($seen[$resolvedStr])) {
                throw new MalformedProjectConfig(\sprintf(
                    'alias "%s" resolves to %s, which duplicates an earlier alias',
                    $entry,
                    $resolvedStr,
                ));
            }
            $seen[$resolvedStr] = true;
            $out[] = $resolved;
        }

        return $out;
    }

    /**
     * @param non-empty-string $raw
     */
    private function resolvePath(string $raw, Path $root): Path
    {
        $path = Path::create($raw);

        return $path->isAbsolute() ? $path : $root->join($raw);
    }
}
