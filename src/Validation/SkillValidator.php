<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

use Internal\Path;
use LLM\Skills\Discovery\SkillEnumerator;
use LLM\Skills\Discovery\SkillFrontmatterReader;
use LLM\Skills\Sync\SkillConflict;

/**
 * Validates skill directories against the Agent Skills specification, the extension keys
 * some agents read, and the rules llm/skills applies when it installs a skill.
 *
 * One instance covers one run: it remembers every skill it saw, so
 * {@see self::reportCollisions()} can compare their directory names.
 *
 * @internal
 */
final class SkillValidator
{
    /**
     * Above this offset llm/skills stops reading `SKILL.md`; see {@see SkillFrontmatterReader}.
     */
    private const PLUGIN_READ_LIMIT = 4096;

    private readonly FrontmatterRules $frontmatter;
    private readonly ContentRules $content;

    /**
     * Skill directories seen so far, by collision key, then by {@see SkillFiles::identity()}
     * so one directory reached under two spellings counts once.
     *
     * @var array<string, array<string, string>>
     */
    private array $names = [];

    /**
     * Source directories seen by {@see self::validateSource()}, by {@see SkillFiles::identity()}.
     *
     * @var array<string, string>
     */
    private array $sources = [];

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly FindingCollector $findings,
        private readonly PathDisplay $paths,
        private readonly ValidatedSkills $validated = new ValidatedSkills(),
    ) {
        $this->frontmatter = new FrontmatterRules($findings);
        $this->content = new ContentRules($findings, $paths);
    }

    /**
     * Validates every skill at or under a path the user named.
     *
     * @param bool $explicit whether the user named the path; a default path holding no
     *        skill is only a warning
     */
    public function validatePath(string $path, bool $explicit): void
    {
        $abs = $this->paths->absolute($path);
        $shown = $this->paths->show($abs);
        if (!\file_exists($abs)) {
            $this->findings->error($shown, null, 'path-missing', 'path does not exist; pass a skill directory, a SKILL.md file, or a directory that contains skills');

            return;
        }
        $skills = SkillFiles::discover($abs, hidden: $hidden);
        foreach ($hidden as $dir) {
            $this->findings->info($this->paths->show($dir), null, 'hidden-dir-skipped', 'hidden skill directory is never installed by llm/skills, so it is not validated; rename it if it is meant to ship');
        }
        if ($skills === [] && $hidden !== []) {
            $explicit and $this->findings->error($shown, null, 'skill-md-missing', 'only hidden skill directories found here, and llm/skills installs none of them; rename the ones meant to ship');

            return;
        }
        if ($skills === []) {
            $message = 'no SKILL.md found here or below; a skill is a directory holding a SKILL.md file';
            $explicit
                ? $this->findings->error($shown, null, 'skill-md-missing', $message . ' — create one or point at the right directory')
                : $this->findings->warn($shown, null, 'skill-md-missing', $message . ' — pass the skills directory explicitly');

            return;
        }
        foreach ($skills as $dir) {
            $this->validateSkill($dir);
        }
        // A named directory of skills may be a package's skills source, which llm/skills
        // copies subdirectory by subdirectory.
        if ($explicit && \is_dir($abs) && !\in_array($abs, $skills, true)) {
            $this->checkContainerChildren($abs);
        }
    }

    /**
     * Validates a declared `extra.skills.source` directory the way llm/skills installs it:
     * every subdirectory is copied as a skill, except a hidden one, which is refused.
     *
     * @param string $dir absolute and normalized
     */
    public function validateSource(string $dir): void
    {
        $shown = $this->paths->show($dir);
        if (!\is_dir($dir)) {
            $this->findings->error($shown, null, 'source-missing', 'declared extra.skills.source directory does not exist, so the package ships no skills from it; create it or fix the path in composer.json');

            return;
        }
        $identity = SkillFiles::identity($dir);
        if (isset($this->sources[$identity])) {
            $this->findings->error($shown, null, 'source-duplicate', \sprintf(
                'declared extra.skills.source directory is the same as "%s", so every skill in it ships twice and a consumer sync aborts on the duplicate names; keep one of the entries in composer.json',
                $this->paths->show($this->sources[$identity]),
            ));

            return;
        }
        $this->sources[$identity] = $dir;
        $children = \array_values(\array_filter(
            \array_map(static fn(string $e): string => "$dir/$e", SkillFiles::entries($dir)),
            \is_dir(...),
        ));
        if ($children === []) {
            $this->findings->warn($shown, null, 'source-empty', 'declared extra.skills.source directory holds no skill directories, so the package ships nothing from it; add skills or drop the entry from composer.json');

            return;
        }
        foreach ($children as $child) {
            if (PathDisplay::name($child)[0] === '.') {
                $this->findings->info($this->paths->show($child), null, 'hidden-dir-skipped', 'hidden directory is never installed by llm/skills, so it is not validated; rename it if it is meant to ship');
            } elseif (SkillFiles::skillFileName($child) === null) {
                if (SkillEnumerator::refusedDirectoryName(PathDisplay::name($child), foldsUnicode: true) !== null) {
                    // Refused by llm/skills, so it is never installed and clashes with nothing.
                    continue;
                }
                // Installed all the same, so it takes part in the name checks.
                $this->names[SkillConflict::collisionKey(PathDisplay::name($child))][SkillFiles::identity($child)] ??= $child;
                $this->findings->warn($this->paths->show($child), null, 'source-subdir-not-skill', 'directory has no SKILL.md, yet llm/skills copies every subdirectory of a declared extra.skills.source as a skill; add a SKILL.md or move it out of the source directory');
            } else {
                $this->validateSkill($child);
                // Left out of a consumer's sync, so it cannot clash with the skills that are installed.
                if (SkillFrontmatterReader::marksInternal((new SkillFrontmatterReader())->read(Path::create($child)))) {
                    unset($this->names[SkillConflict::collisionKey(SkillFiles::onDiskName($child))][SkillFiles::identity($child)]);
                }
            }
        }
    }

    /**
     * Reports skill directories whose names differ but collide case-insensitively or after
     * Unicode NFC, as llm/skills compares them.
     */
    public function reportCollisions(): void
    {
        foreach ($this->names as $dirs) {
            $spellings = \array_unique(\array_map(PathDisplay::name(...), $dirs));
            if (\count($spellings) < 2) {
                continue;
            }
            foreach ($dirs as $dir) {
                $name = PathDisplay::name($dir);
                $others = \array_filter($dirs, static fn(string $d): bool => PathDisplay::name($d) !== $name);
                $this->findings->error($this->paths->show($dir), null, 'name-collision', \sprintf(
                    'skill directory "%s" collides with %s when compared case-insensitively/after Unicode NFC, which aborts an llm/skills sync and overwrites on case-insensitive filesystems; rename one of them',
                    $name,
                    \implode(', ', \array_map(fn(string $d): string => '"' . $this->paths->show($d) . '"', $others)),
                ));
            }
        }
    }

    /**
     * Reports skill directories of the same name: shipped by one package, they abort a
     * consumer's sync. Only for skills that install side by side, such as the subdirectories
     * of one package's declared sources.
     */
    public function reportDuplicates(): void
    {
        foreach ($this->names as $dirs) {
            foreach ($dirs as $dir) {
                $name = PathDisplay::name($dir);
                $copies = \array_filter($dirs, static fn(string $d): bool => $d !== $dir && PathDisplay::name($d) === $name);
                if ($copies === []) {
                    continue;
                }
                $this->findings->error($this->paths->show($dir), null, 'duplicate-shipped-skill', \sprintf(
                    'skill "%s" also ships from %s, and a consumer sync aborts on duplicate names from one package; rename one of them or drop one of the source directories',
                    $name,
                    \implode(', ', \array_map(fn(string $d): string => '"' . $this->paths->show($d) . '"', $copies)),
                ));
            }
        }
    }

    /**
     * Validates one skill directory; a failure inside the validator becomes a finding.
     *
     * @param string $dir absolute and normalized
     */
    public function validateSkill(string $dir): void
    {
        try {
            $this->validateSkillUnguarded($dir);
        } catch (\Throwable $e) {
            $this->findings->error($this->paths->show($dir), null, 'validation-failed', 'skill could not be validated (' . $e->getMessage() . '); inspect SKILL.md by hand');
        }
    }

    private function validateSkillUnguarded(string $dir): void
    {
        $base = SkillFiles::onDiskName($dir);
        $dir = \rtrim(PathDisplay::parent($dir), '/') . '/' . $base;
        $shownDir = $this->paths->show($dir);
        $identity = SkillFiles::identity($dir);
        $this->names[SkillConflict::collisionKey($base)][$identity] ??= $dir;
        if (!$this->validated->claim($identity)) {
            return;
        }

        // Judged as on a consumer with mbstring and intl, so the verdict does not depend on
        // the extensions of the machine running the validator.
        $reason = SkillEnumerator::refusedDirectoryName($base, foldsUnicode: true);
        if ($reason !== null) {
            $this->findings->error($shownDir, null, 'dir-name-refused', "skill directory name \"$base\" $reason, so llm/skills refuses to install it; rename the directory (lowercase letters, digits and hyphens are safe)");
        } elseif (\preg_match('/[^\x00-\x7f]/', $base) === 1) {
            $this->findings->warn($shownDir, null, 'dir-name-non-ascii', "skill directory name \"$base\" is not ASCII, which llm/skills installs only where PHP has the mbstring and intl extensions; use lowercase ASCII letters, digits and hyphens to install everywhere");
        }

        $fileName = SkillFiles::skillFileName($dir);
        if ($fileName === null) {
            $this->findings->error($shownDir, null, 'skill-md-missing', 'missing required file SKILL.md; create SKILL.md with name and description frontmatter');

            return;
        }
        $file = $this->paths->show("$dir/$fileName");
        if ($fileName !== 'SKILL.md') {
            $this->findings->warn($file, null, 'skill-md-wrong-case', "file is named \"$fileName\", which agents on case-sensitive filesystems and llm/skills do not find; rename it to SKILL.md");
        }
        $content = @\file_get_contents("$dir/$fileName");
        if ($content === false) {
            $this->findings->error($file, null, 'skill-md-unreadable', 'SKILL.md cannot be read; fix its permissions');

            return;
        }
        if (\preg_match('//u', $content) !== 1) {
            $this->findings->error($file, null, 'not-utf8', 'SKILL.md is not valid UTF-8; re-save it with UTF-8 encoding');

            return;
        }

        $doc = SkillDocument::parse($content);
        if ($doc->bom) {
            $this->findings->warn($file, 1, 'utf8-bom', 'file starts with a UTF-8 byte-order mark, so strict parsers such as skills-ref do not see "---" first; save it without BOM');
        }
        if ($doc->missing === SkillDocument::FRONTMATTER_MISSING) {
            $this->findings->error($file, 1, 'frontmatter-missing', 'SKILL.md must start with YAML frontmatter (---) on its very first line; add a --- block with name and description');
        } elseif ($doc->missing === SkillDocument::FRONTMATTER_UNCLOSED) {
            $this->findings->error($file, 1, 'frontmatter-unclosed', 'SKILL.md frontmatter is not closed with ---; add a line containing only --- after the last frontmatter key');

            return;
        } elseif ($doc->frontmatterEnd !== null && $doc->frontmatterEnd > self::PLUGIN_READ_LIMIT) {
            $this->findings->warn($file, $doc->bodyStart - 1, 'frontmatter-over-4k', 'frontmatter ends past the first 4 KiB, which is all llm/skills reads, so it cannot see name, description or metadata.internal; shorten the frontmatter');
        }
        foreach ($doc->notes as $note) {
            $this->findings->warn($file, $note->line, $note->code, $note->message);
        }
        if ($doc->frontmatterError !== null) {
            $this->findings->error($file, $doc->frontmatterErrorLine, 'yaml-invalid', 'invalid YAML in frontmatter: ' . $doc->frontmatterError);
        } elseif ($doc->frontmatter !== null) {
            $this->frontmatter->check($doc->frontmatter, $dir, $file);
        }
        $this->content->checkBody($doc, $dir, "$dir/$fileName", $file);
        $this->content->checkReferenceFiles($dir);
    }

    /**
     * Reports subdirectories of a directory of skills that are no skills themselves and hold
     * none further down: llm/skills would copy them as skills from a declared source.
     */
    private function checkContainerChildren(string $dir): void
    {
        $children = \array_values(\array_filter(
            \array_map(static fn(string $e): string => "$dir/$e", SkillFiles::entries($dir)),
            \is_dir(...),
        ));
        $hasSkill = false;
        foreach ($children as $child) {
            if (SkillFiles::skillFileName($child) !== null) {
                $hasSkill = true;
                break;
            }
        }
        if (!$hasSkill) {
            return;
        }
        foreach ($children as $child) {
            if (PathDisplay::name($child)[0] === '.' || SkillFiles::skillFileName($child) !== null
                || SkillFiles::discover($child, 2) !== []) {
                continue;
            }
            $this->findings->warn($this->paths->show($child), null, 'source-subdir-not-skill', 'directory has no SKILL.md, yet llm/skills copies every subdirectory of a declared extra.skills.source as a skill; add a SKILL.md or move it out of the source directory');
        }
    }
}
