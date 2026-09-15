<?php

declare(strict_types=1);

namespace LLM\Skills\Init;

use Internal\Path;
use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Filesystem\LinkGuard;

/**
 * Looks at the project root and works out which agent directories the
 * project already uses, so `skills:init` can propose a layout instead of
 * asking the user to describe one they already have.
 *
 * The probe is observational: it never creates, moves or deletes
 * anything. Three states matter per candidate directory:
 *
 * - **absent, but the agent directory exists** (`.claude` without
 *   `.claude/skills`) — the agent is in use, so the candidate is a good
 *   alias: sync will create the link;
 * - **a link** — an existing layout. One that resolves to another
 *   candidate tells us which directory the project already treats as the
 *   real one, so that directory becomes the target and the link stays an
 *   alias;
 * - **a directory with its own content** — an alias cannot be created
 *   over it ({@see \LLM\Skills\Sync\SymlinkLinker} refuses to replace a
 *   real directory), so it is reported as a collision rather than
 *   proposed and left for the user to resolve.
 */
final readonly class AgentWorkspaceProbe
{
    /**
     * Directories worth probing for. Same set the wizard offers as
     * numbered options, so a detected path is always one the user could
     * have picked by hand.
     *
     * @var list<non-empty-string>
     */
    public const KNOWN_DIRS = InteractiveInitWizard::COMMON_ALIASES;

    public function probe(Path $projectRoot): WorkspaceLayout
    {
        $states = [];
        foreach (self::KNOWN_DIRS as $dir) {
            $states[$dir] = $this->inspect($projectRoot, $dir);
        }

        $target = $this->resolveTarget($states);

        $aliases = [];
        $collisions = [];
        $detected = false;
        foreach ($states as $dir => $state) {
            if ($state['agentDirExists'] || $state['exists']) {
                $detected = true;
            }
            if ($dir === $target) {
                continue;
            }
            // An agent directory that is not in use is not proposed: an
            // alias into a `.cursor/` that does not exist would create
            // the very clutter the user avoided by not installing Cursor.
            if (!$state['agentDirExists']) {
                continue;
            }
            if ($state['link'] !== null) {
                if ($state['link'] === $states[$target]['real']) {
                    $aliases[] = $dir;
                } else {
                    $collisions[] = $dir;
                }
                continue;
            }
            if ($state['exists']) {
                $collisions[] = $dir;
            } else {
                $aliases[] = $dir;
            }
        }

        return new WorkspaceLayout(
            target: $target,
            aliases: $aliases,
            collisions: $collisions,
            detected: $detected,
        );
    }

    /**
     * The default target wins unless the project already keeps its skills
     * elsewhere: a candidate that another candidate links to is, by
     * construction, the directory this project treats as the real one.
     *
     * @param array<non-empty-string, array{exists: bool, link: string|null, real: string|null,
     *        agentDirExists: bool}> $states
     *
     * @return non-empty-string
     *
     * @psalm-pure
     */
    private function resolveTarget(array $states): string
    {
        foreach ($states as $state) {
            if ($state['link'] === null) {
                continue;
            }
            foreach ($states as $candidate => $candidateState) {
                if ($candidateState['link'] === null && $candidateState['real'] === $state['link']) {
                    return $candidate;
                }
            }
        }

        return ProjectConfig::DEFAULT_TARGET;
    }

    /**
     * @param non-empty-string $dir
     *
     * @return array{exists: bool, link: string|null, real: string|null, agentDirExists: bool}
     */
    private function inspect(Path $projectRoot, string $dir): array
    {
        $absolute = (string) $projectRoot->join($dir);
        $exists = \file_exists($absolute) || \is_link($absolute);
        $isLink = $exists && LinkGuard::isLink($absolute);
        $resolved = $exists ? \realpath($absolute) : false;
        $real = $resolved === false || $resolved === '' ? null : $resolved;

        // A linked `.claude` is an in-project path by name only: creating
        // `.claude/skills` inside it writes wherever the link points, and
        // the planner's containment check is lexical, so it would not
        // notice. A proposal the user confirms blind must not be able to
        // do that, so a linked parent counts as "not in use" here.
        $agentDir = (string) $projectRoot->join(\dirname($dir));

        return [
            'exists' => $exists,
            'link' => $isLink ? $real : null,
            'real' => $real,
            'agentDirExists' => \is_dir($agentDir) && !LinkGuard::isLink($agentDir),
        ];
    }
}
