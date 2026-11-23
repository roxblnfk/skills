<?php

declare(strict_types=1);

namespace LLM\Skills\Pipeline;

use Composer\Composer;
use Internal\Path;
use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Validation\PathDisplay;
use LLM\Skills\Validation\ValidatedSkills;

/**
 * The project a {@see Pipeline} inspects, and the facts its stages publish for later ones.
 *
 * @internal
 */
final class ProjectContext
{
    /** Raw `extra` of the root `composer.json`; `null` until a stage reads it, or when there is none. */
    public mixed $extra = null;

    /** Resolved project configuration; `null` until a stage resolves it, or when it is malformed. */
    public ?ProjectConfig $config = null;

    /** The file holding the shared project configuration: `skills.json`, else `composer.json`. */
    public string $configFile = 'composer.json';

    /** Directory skills are installed into; `null` until a stage resolves it, or when it is invalid. */
    public ?Path $target = null;

    /**
     * Directories the root package declares in `extra.skills.source`, absolute and normalized.
     *
     * @var list<string>
     */
    public array $distributedSources = [];

    /**
     * {@see \LLM\Skills\Sync\SkillConflict::collisionKey()} of every skill a sync would install
     * from donors, so an installed copy of one is known to come from its donor.
     *
     * @var array<string, true>
     */
    public array $donorSkillKeys = [];

    /**
     * Whether every donor was read. Without `--fetch` the donors behind the network are not,
     * so an installed skill matching no donor may still be a copy of one of theirs.
     */
    public bool $allDonorsRead = false;

    public readonly PathDisplay $paths;

    /** Skill directories already validated, shared so a directory reached twice is checked once. */
    public readonly ValidatedSkills $validatedSkills;

    /**
     * @param Path $projectRoot absolute project root; findings show paths relative to it
     * @param Composer|null $composer the project's Composer instance, `null` when none could be
     *        loaded; installed packages are read through it
     * @param bool $fetch whether donors that need the network are fetched
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly Path $projectRoot,
        public readonly ?Composer $composer = null,
        public readonly bool $fetch = false,
    ) {
        $this->paths = new PathDisplay((string) $projectRoot);
        $this->validatedSkills = new ValidatedSkills();
    }
}
