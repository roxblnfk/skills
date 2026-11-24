<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

use LLM\Skills\Filesystem\LinkGuard;

/**
 * Checks the Markdown of a skill: body size, links and paths to bundled files, how deep
 * references nest, and whether long reference files open with a table of contents.
 * Paths are absolute, normalized forward-slash strings, see {@see PathDisplay}.
 *
 * @internal
 */
final class ContentRules
{
    private const MAX_BODY_LINES = 500;
    private const MAX_BODY_TOKENS = 5000;
    private const TOC_THRESHOLD_LINES = 100;

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly FindingCollector $findings,
        private readonly PathDisplay $paths,
    ) {}

    /**
     * @param string $skillMd the skill file as found on disk, absolute
     * @param string $file the same file as shown in findings
     */
    public function checkBody(SkillDocument $doc, string $skillDir, string $skillMd, string $file): void
    {
        $lines = \count(\preg_split('/\r\n|\n|\r/', \rtrim($doc->body)) ?: []);
        if ($lines > self::MAX_BODY_LINES) {
            $this->findings->warn($file, null, 'body-too-long', "SKILL.md body has $lines lines, over the recommended 500; move detail into reference files linked from SKILL.md");
        }
        // About four characters per token; close enough for a size warning.
        $tokens = \intdiv(Text::length($doc->body), 4);
        if ($tokens > self::MAX_BODY_TOKENS) {
            $this->findings->warn($file, null, 'body-too-many-tokens', "SKILL.md body is about $tokens tokens, over the recommended 5000; move detail into reference files linked from SKILL.md");
        }

        $direct = $this->checkLinks($doc->body, $doc->bodyStart, $skillMd, $skillDir, true);
        $directKeys = [];
        foreach ($direct as $link) {
            $directKeys[self::key($link['path'])] = true;
        }
        $checked = [];
        foreach ($direct as $link) {
            $key = self::key($link['path']);
            if ($key === self::key($skillMd) || isset($checked[$key])) {
                continue;
            }
            $checked[$key] = true;
            $text = (string) @\file_get_contents($link['path']);
            foreach ($this->checkLinks($text, 1, $link['path'], $skillDir, false) as $deeper) {
                $deeperKey = self::key($deeper['path']);
                if ($deeperKey === self::key($skillMd) || $deeperKey === $key || isset($directKeys[$deeperKey])) {
                    continue;
                }
                $this->findings->warn($this->paths->show($link['path']), $deeper['line'], 'reference-nested', \sprintf(
                    'links to "%s", which SKILL.md does not link directly; agents may read nested references only partially, so link it from SKILL.md (references one level deep)',
                    $this->paths->show($deeper['path']),
                ));
            }
        }
    }

    public function checkReferenceFiles(string $skillDir): void
    {
        $files = [];
        $walk = static function (string $dir, int $depth) use (&$walk, &$files): void {
            foreach (SkillFiles::entries($dir) as $entry) {
                $path = "$dir/$entry";
                if (\is_dir($path)) {
                    if ($depth < 4 && $entry[0] !== '.'
                        && !\in_array($entry, ['assets', 'scripts', ...SkillFiles::SKIP_DIRS], true)
                        && !LinkGuard::isLink($path)) {
                        /** @var \Closure(string, int): void $walk */
                        $walk($path, $depth + 1);
                    }
                } elseif (\str_ends_with(\strtolower($entry), '.md') && ($depth > 0 || \strcasecmp($entry, 'SKILL.md') !== 0)) {
                    $files[] = $path;
                }
            }
        };
        $walk($skillDir, 0);

        /** @var list<string> $files */
        foreach ($files as $file) {
            $lines = \preg_split('/\r\n|\n|\r/', \rtrim((string) @\file_get_contents($file))) ?: [];
            if (\count($lines) <= self::TOC_THRESHOLD_LINES || Markdown::hasTableOfContents($lines)) {
                continue;
            }
            $this->findings->warn($this->paths->show($file), null, 'reference-no-toc', \sprintf(
                'reference file has %d lines but no table of contents near the top; add a short "Contents" list of its sections so agents see the whole scope from a partial read',
                \count($lines),
            ));
        }
    }

    /**
     * Comparison key of an existing file, equal for every spelling of it.
     */
    private static function key(string $path): string
    {
        return SkillFiles::identity($path);
    }

    /**
     * A local link target as an absolute path; `null` for URLs, anchors, absolute paths
     * and templated targets.
     *
     * @psalm-mutation-free
     */
    private function resolveLink(string $target, string $fromDir): ?string
    {
        if (\preg_match('~^(?:[a-z][a-z0-9+.-]*:|#|//|/)~i', $target) === 1 || \strpbrk($target, '{}$*') !== false) {
            return null;
        }
        // Backslashes read as the Windows author meant them; the link-backslash warning
        // covers the rest.
        $path = \str_replace('\\', '/', \rawurldecode((string) \preg_replace('/[#?].*$/s', '', $target)));
        // Checked again once decoded: stat()ing a `//host/share` target from an untrusted
        // skill would open a network connection, on Windows with the user's credentials.
        if ($path === '' || \preg_match('~^(?:[a-z][a-z0-9+.-]*:|/)~i', $path) === 1 || \str_contains($path, "\0")) {
            return null;
        }

        return $this->paths->absolute($path, $fromDir);
    }

    /**
     * Checks one Markdown file's links, and in `SKILL.md` also the bundled-file paths named
     * in inline code.
     *
     * @param bool $inlinePaths whether to check inline-code paths; reference files often
     *        quote paths of the upstream repository instead
     *
     * @return list<array{line: int, path: string}> the Markdown files inside the skill it links to
     */
    private function checkLinks(string $text, int $firstLine, string $file, string $skillDir, bool $inlinePaths): array
    {
        $shown = $this->paths->show($file);
        $mdLinks = [];
        foreach (Markdown::links($text, $firstLine) as $link) {
            $target = $link['target'];
            $resolved = $this->resolveLink($target, PathDisplay::parent($file));
            if ($resolved === null) {
                continue;
            }
            if (\str_contains($target, '\\')) {
                $this->findings->warn($shown, $link['line'], 'link-backslash', "link \"$target\" uses backslashes, which break on non-Windows systems; use forward slashes");
            }
            if (!\file_exists($resolved)) {
                $this->findings->error($shown, $link['line'], 'link-missing', "link target \"$target\" does not exist; fix the path or add the file");
                continue;
            }
            if (!PathDisplay::isWithin($resolved, $skillDir)) {
                $this->findings->info($shown, $link['line'], 'link-outside-skill', "link \"$target\" leaves the skill directory, so it resolves only where that file is installed alongside; copy the file into the skill if it must always be available");
                continue;
            }
            if (\str_ends_with(\strtolower($resolved), '.md') && \is_file($resolved)) {
                $mdLinks[] = ['line' => $link['line'], 'path' => $resolved];
            }
        }

        if ($inlinePaths) {
            $this->checkInlinePaths($text, $firstLine, $file, $skillDir, $shown);
        }

        return $mdLinks;
    }

    /**
     * Inline-code paths such as `references/api.md` or `scripts/run.py` that name no file.
     */
    private function checkInlinePaths(string $text, int $firstLine, string $file, string $skillDir, string $shown): void
    {
        foreach (Markdown::proseLines($text) as $i => $line) {
            if (\preg_match_all('/(`+)((?:(?!\1).)+)\1/', $line, $spans) === 0) {
                continue;
            }
            foreach ($spans[2] as $span) {
                if (\preg_match_all(
                    '~(?<![\w./\\\\-])(?:\./)?(?:references|scripts|assets)([/\\\\])[\w.\\\\/-]*[\w-]~',
                    $span,
                    $matches,
                    \PREG_SET_ORDER,
                ) === 0) {
                    continue;
                }
                foreach ($matches as $match) {
                    $path = $match[0];
                    if (\str_contains($path, '\\')) {
                        $this->findings->warn($shown, $firstLine + $i, 'path-backslash', "path \"$path\" uses backslashes, which break on non-Windows systems; use forward slashes");
                        $path = \str_replace('\\', '/', $path);
                    }
                    if (!\str_contains(PathDisplay::name($path), '.')
                        || \file_exists($this->paths->absolute($path, $skillDir))
                        || \file_exists($this->paths->absolute($path, PathDisplay::parent($file)))) {
                        continue;
                    }
                    // Prose often names a file of a sibling skill ("see other-skill → references/x.md").
                    $parent = (string) \preg_replace('/[*?\[]/', '[$0]', \rtrim(PathDisplay::parent($skillDir), '/'));
                    $siblings = \glob($parent . '/*/' . \ltrim($path, './'), \GLOB_NOSORT);
                    if ($siblings !== false && $siblings !== []) {
                        continue;
                    }
                    $this->findings->warn($shown, $firstLine + $i, 'path-missing', "path \"$path\" is not present in the skill directory; fix the path or add the file");
                }
            }
        }
    }
}
