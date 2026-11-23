#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace LlmSkills\Scripts;

require_once __DIR__ . '/lib/common.php';

$usage = <<<'TXT'
Usage: php check-project.php [--json] [--root=DIR]

Checks a project's llm/skills setup: skills.json and skills.local.json (validity,
unknown and deprecated keys, trust patterns, sources), legacy inline extra.skills,
git hygiene of the target, aliases and skills.local.json, and - for a package that
ships skills - its extra.skills.source, .gitattributes export-ignore and duplicate
skill copies. The shipped skills themselves are validated by
"composer skills:lint"; the run ends with that command for them.

Options:
  --root=DIR  project root to check (default: current directory)
  --json      print findings as a JSON array of {level,file,line,message,code}
  -h, --help  show this help

Output: one finding per line "ERROR|WARN|INFO <path>[:<line>]: <message>", then
"N error(s), M warning(s)". Exit code: 0 no errors, 1 errors found, 2 usage error.

TXT;

final class ProjectChecker
{
    /** Keys of skills.json when no schema file is reachable. */
    private const EMBEDDED_KEYS = ['$schema', 'target', 'aliases', 'discovery', 'auto-sync', 'clean', 'path-from-root',
        'dependencies', 'vendor-sources', 'sources'];

    /** Deprecated keys still accepted in skills.json, with their replacement. */
    private const DEPRECATED = [
        'remote' => 'rename it to "sources"',
        'trusted' => 'move the patterns to dependencies.composer.trusted',
        'trusted-replace' => 'move it to dependencies.composer.trusted-replace',
        'local' => 'move the toggles to dependencies.<manager> (true/false)',
    ];

    /** Project keys read from an inline composer.json extra.skills block. */
    private const INLINE_KEYS = ['target', 'aliases', 'dependencies', 'trusted', 'trusted-replace', 'discovery', 'auto-sync',
        'path-from-root', 'local', 'vendor-sources', 'remote'];

    private const ADAPTERS = ['github', 'gitlab', 'bitbucket', 'composer', 'npm', 'go', 'skills.sh', 'http', 'zip', 'dir'];

    private const IMPLEMENTED_ADAPTERS = ['github', 'gitlab', 'dir'];

    /** Folders llm/skills searches for skills in a package that declares no extra.skills.source. */
    private const CONTAINERS = ['.agents/skills', '.claude/skills', '.cursor/skills', 'skills', 'resources/skills'];

    private ?bool $git = null;

    /** @var list<string> directories holding shipped skills, for the lint hint */
    private array $shippedDirs = [];

    public function __construct(private readonly Report $report, private readonly string $root)
    {
    }

    public function run(): void
    {
        $composer = $this->readComposer();
        $shared = $this->loadConfig('skills.json', false);
        $local = $this->loadConfig('skills.local.json', true);
        $inline = $this->checkInline($composer, $shared !== null);

        $target = $local['target'] ?? $shared['target'] ?? ($shared === null ? $inline['target'] ?? null : null) ?? '.agents/skills';
        $aliases = [];
        foreach ([$shared === null ? $inline : $shared, $local] as $layer) {
            foreach ((\is_array($layer['aliases'] ?? null) ? $layer['aliases'] : []) as $a) {
                if (\is_string($a) && $a !== '') {
                    $aliases[self::normPath($a)] = $a;
                }
            }
        }
        $target = \is_string($target) && $target !== '' ? $target : '.agents/skills';

        // Aliases of every layer are compared with the effective target (local, else shared, else default).
        $layers = $shared === null ? ['composer.json' => $inline] : ['skills.json' => $shared];
        $layers['skills.local.json'] = $local;
        foreach ($layers as $file => $layer) {
            foreach ((\is_array($layer['aliases'] ?? null) ? $layer['aliases'] : []) as $a) {
                if (\is_string($a) && self::normPath($a) === self::normPath($target)) {
                    $this->report->error($this->path($file), Fs::jsonKeyLine((string) @\file_get_contents($this->root . '/' . $file), 'aliases'), 'alias-equals-target', "alias \"$a\" equals the target \"$target\"; remove it from aliases");
                }
            }
        }

        $this->checkPluginAllowed($composer);
        $this->checkGit($target, \array_values($aliases));
        $this->checkAuthor($composer, self::normPath($target));
    }

    private function path(string $rel): string
    {
        return Fs::rel($this->root . '/' . $rel);
    }

    private static function normPath(string $p): string
    {
        $p = \str_replace('\\', '/', $p);
        $p = (string) \preg_replace('~^(\./)+~', '', $p);

        return \rtrim($p, '/');
    }

    /**
     * @return array<string, mixed>|null decoded composer.json, null when absent or broken
     */
    private function readComposer(): ?array
    {
        $file = $this->root . '/composer.json';
        if (!\is_file($file)) {
            $this->report->info($this->path('composer.json'), null, 'composer-json-missing', 'no composer.json here, so only the standalone skills binary can sync this project; run the check from the Composer project root if that is wrong');

            return null;
        }
        $data = Fs::readJson($file, $error);
        if ($error !== null || !\is_object($data)) {
            $this->report->error($this->path('composer.json'), null, 'composer-json-invalid', 'composer.json is not a valid JSON object (' . ($error ?? 'not an object') . '); fix it, since Composer and llm/skills cannot read it');

            return null;
        }

        return self::toArray($data);
    }

    private static function toArray(mixed $v): mixed
    {
        if ($v instanceof \stdClass) {
            $out = [];
            foreach (\get_object_vars($v) as $k => $x) {
                $out[(string) $k] = self::toArray($x);
            }

            return $out;
        }

        return \is_array($v) ? \array_map(self::toArray(...), $v) : $v;
    }

    /**
     * @return list<string>
     */
    private function schemaKeys(): array
    {
        foreach ([$this->root . '/vendor/llm/skills/resources/skills.schema.json', \dirname(__DIR__, 3) . '/skills.schema.json'] as $f) {
            if (\is_file($f)) {
                $schema = Fs::readJson($f, $error);
                if ($error === null && isset($schema->properties) && \is_object($schema->properties)) {
                    return \array_keys(\get_object_vars($schema->properties));
                }
            }
        }

        return self::EMBEDDED_KEYS;
    }

    /**
     * @return array<string, mixed>|null the config, or null when the file is absent or unusable
     */
    private function loadConfig(string $name, bool $isLocal): ?array
    {
        $file = $this->root . '/' . $name;
        if (!\is_file($file)) {
            return null;
        }
        $rel = $this->path($name);
        $text = (string) @\file_get_contents($file);
        if (\str_starts_with($text, "\xEF\xBB\xBF")) {
            $this->report->error($rel, 1, 'config-bom', "$name starts with a UTF-8 byte-order mark, which llm/skills rejects as invalid JSON; save the file as UTF-8 without BOM");

            return null;
        }
        $data = Fs::readJson($file, $error);
        if ($error !== null) {
            $this->report->error($rel, null, 'config-invalid-json', "$name is not valid JSON ($error), and llm/skills aborts on a malformed config; fix the syntax");

            return null;
        }
        if (!\is_object($data)) {
            $this->report->error($rel, null, 'config-not-object', "$name must hold a JSON object at its root; wrap the settings in { }");

            return null;
        }
        $cfg = self::toArray($data);
        $allowed = $this->schemaKeys();
        foreach ($cfg as $key => $value) {
            // PHP turns numeric JSON keys such as "1" into integers.
            $key = (string) $key;
            $line = Fs::jsonKeyLine($text, $key);
            if ($isLocal && $value === null) {
                $this->report->error($rel, $line, 'config-null-value', "\"$key\" is null, which skills.local.json rejects; remove the key to keep the shared value");
            }
            if (isset(self::DEPRECATED[$key])) {
                $isLocal
                    ? $this->report->error($rel, $line, 'config-key-rejected', "deprecated key \"$key\" is rejected in skills.local.json; " . self::DEPRECATED[$key])
                    : $this->report->warn($rel, $line, 'config-deprecated-key', "deprecated key \"$key\" still works but will be migrated by the next composer skills:update; " . self::DEPRECATED[$key]);
                continue;
            }
            if (!\in_array($key, $allowed, true)) {
                $guess = Text::nearest($key, [...$allowed, ...\array_keys(self::DEPRECATED)]);
                $this->report->error($rel, $line, 'config-unknown-key', "unknown top-level key \"$key\" makes llm/skills abort; "
                    . ($guess !== null ? "did you mean \"$guess\"?" : 'remove it (allowed: ' . \implode(', ', \array_diff($allowed, ['$schema'])) . ')'));
            }
        }
        if (isset($cfg['remote'], $cfg['sources'])) {
            $this->report->error($rel, Fs::jsonKeyLine($text, 'remote'), 'config-remote-and-sources', '"remote" and "sources" cannot both be set; merge the entries into "sources" and delete "remote"');
        }
        foreach (['trusted', 'trusted-replace', 'local'] as $old) {
            if (isset($cfg[$old], $cfg['dependencies'])) {
                $this->report->error($rel, Fs::jsonKeyLine($text, $old), 'config-deprecated-with-dependencies', "deprecated \"$old\" cannot be combined with \"dependencies\"; " . self::DEPRECATED[$old]);
            }
        }
        $this->checkValues($cfg, $rel, $text, false);

        return $cfg;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function checkValues(array $cfg, string $rel, string $text, bool $inline): void
    {
        $r = $this->report;
        $at = static fn(string $k): ?int => Fs::jsonKeyLine($text, $k);
        if (\array_key_exists('target', $cfg) && $cfg['target'] !== null && (!\is_string($cfg['target']) || \trim($cfg['target']) === '')) {
            $r->error($rel, $at('target'), 'config-invalid-value', '"target" must be a non-empty path string such as ".agents/skills"; fix the value');
        }
        foreach (['discovery', 'auto-sync', 'clean', 'vendor-sources', 'trusted-replace'] as $k) {
            if (\array_key_exists($k, $cfg) && $cfg[$k] !== null && !\is_bool($cfg[$k])) {
                $r->error($rel, $at($k), 'config-invalid-value', "\"$k\" must be true or false; fix the value");
            }
        }
        if (\array_key_exists('aliases', $cfg) && $cfg['aliases'] !== null) {
            $aliases = $cfg['aliases'];
            if (!\is_array($aliases) || !\array_is_list($aliases)) {
                $r->error($rel, $at('aliases'), 'config-invalid-value', '"aliases" must be a list of paths such as [".claude/skills"]; fix the value');
            } else {
                $seen = [];
                foreach ($aliases as $a) {
                    if (!\is_string($a) || \trim($a) === '') {
                        $r->error($rel, $at('aliases'), 'config-invalid-value', 'every alias must be a non-empty path string; remove the empty or non-string entry');
                        continue;
                    }
                    $n = self::normPath($a);
                    if (isset($seen[$n])) {
                        $r->error($rel, $at('aliases'), 'alias-duplicate', "alias \"$a\" is listed twice; remove the duplicate");
                    }
                    $seen[$n] = true;
                }
            }
        }
        if (isset($cfg['path-from-root'])) {
            $p = $cfg['path-from-root'];
            if (!\is_string($p) || $p === '' || Fs::isAbsolute($p) || \preg_match('~(^|[/\\\\])\.{1,2}([/\\\\]|$)|//~', $p) === 1) {
                $r->error($rel, $at('path-from-root'), 'config-invalid-value', '"path-from-root" must be a relative path of plain segments such as "packages/api"; fix the value');
            } elseif (\preg_match('~[/\\\\]$|^[A-Za-z]:~', $p) === 1) {
                $r->warn($rel, $at('path-from-root'), 'config-suspicious-value', "\"path-from-root\" \"$p\" has a trailing slash or a drive prefix, which may not match the project location; write plain segments such as \"packages/api\"");
            }
        }
        if (isset($cfg['trusted'])) {
            // The legacy top-level list tolerates duplicates.
            $this->checkTrustList($cfg['trusted'], 'trusted', $rel, $at('trusted'), true, false);
        }
        if (isset($cfg['dependencies'])) {
            $deps = $cfg['dependencies'];
            if (!\is_array($deps) || ($deps !== [] && \array_is_list($deps))) {
                $r->error($rel, $at('dependencies'), 'config-invalid-value', '"dependencies" must be an object keyed by composer, npm or go; fix the value');
            } else {
                foreach ($deps as $manager => $conf) {
                    if (!\in_array($manager, ['composer', 'npm', 'go'], true)) {
                        $r->error($rel, $at((string) $manager), 'config-unknown-key', "unknown package manager \"$manager\" in dependencies; use composer, npm or go");
                        continue;
                    }
                    if (\is_bool($conf)) {
                        continue;
                    }
                    if (!\is_array($conf) || ($conf !== [] && \array_is_list($conf))) {
                        $r->error($rel, $at((string) $manager), 'config-invalid-value', "dependencies.$manager must be true, false or an object with enabled/trusted/trusted-replace; fix the value");
                        continue;
                    }
                    foreach ($conf as $k => $v) {
                        if (!\in_array($k, ['enabled', 'trusted', 'trusted-replace'], true)) {
                            $guess = Text::nearest((string) $k, ['enabled', 'trusted', 'trusted-replace']);
                            $r->error($rel, $at((string) $k), 'config-unknown-key', "unknown key \"$k\" in dependencies.$manager; " . ($guess !== null ? "did you mean \"$guess\"?" : 'allowed: enabled, trusted, trusted-replace'));
                        } elseif ($k !== 'trusted' && !\is_bool($v)) {
                            $r->error($rel, $at((string) $k), 'config-invalid-value', "dependencies.$manager.$k must be true or false; fix the value");
                        }
                    }
                    if (isset($conf['trusted'])) {
                        $this->checkTrustList($conf['trusted'], "dependencies.$manager.trusted", $rel, $at('trusted'), $manager === 'composer');
                    }
                    if ($manager !== 'composer' && ($conf['enabled'] ?? false) === true) {
                        $r->info($rel, $at((string) $manager), 'manager-not-implemented', "dependencies.$manager is accepted but llm/skills has no $manager provider yet, so it finds nothing there; no action needed unless you expected skills from $manager");
                    }
                }
            }
        }
        foreach (['sources', 'remote'] as $key) {
            if (isset($cfg[$key]) && !$inline) {
                $this->checkSources($cfg[$key], $key, $rel, $text);
            }
        }
    }

    private function checkTrustList(mixed $list, string $label, string $rel, ?int $line, bool $composer = true, bool $unique = true): void
    {
        if (!\is_array($list) || !\array_is_list($list)) {
            $this->report->error($rel, $line, 'config-invalid-value', "$label must be a list of patterns such as [\"acme/*\"]; fix the value");

            return;
        }
        $seen = [];
        foreach ($list as $p) {
            if (!\is_string($p) || $p === '') {
                $this->report->error($rel, $line, 'trust-pattern-invalid', "$label holds an empty or non-string entry; remove it");
                continue;
            }
            if ($unique && isset($seen[$p])) {
                $this->report->error($rel, $line, 'trust-pattern-duplicate', "$label lists \"$p\" twice; remove the duplicate");
            }
            $seen[$p] = true;
            if ($composer && \trim($p) === '*') {
                $this->report->error($rel, $line, 'trust-pattern-star', "bare \"*\" in $label is rejected by llm/skills; trust vendors one by one as \"vendor/*\"");
            } elseif ($composer && \preg_match('~^[^/\s]+/[^/\s]+$~', $p) !== 1) {
                $this->report->error($rel, $line, 'trust-pattern-invalid', "trust pattern \"$p\" in $label is invalid; use \"vendor/package\" or \"vendor/*\"");
            }
        }
    }

    private function checkSources(mixed $sources, string $key, string $rel, string $text): void
    {
        $r = $this->report;
        $line = Fs::jsonKeyLine($text, $key);
        if (!\is_array($sources) || !\array_is_list($sources)) {
            $r->error($rel, $line, 'config-invalid-value', "\"$key\" must be a list of source objects; fix the value");

            return;
        }
        $seen = [];
        foreach ($sources as $i => $s) {
            $label = "{$key}[$i]";
            if (!\is_array($s) || ($s !== [] && \array_is_list($s))) {
                $r->error($rel, $line, 'source-invalid', "$label must be an object such as {\"from\": \"github\", \"package\": \"acme/skills\"}; fix the entry");
                continue;
            }
            $from = $s['from'] ?? null;
            if (!\is_string($from) || $from === '') {
                $r->error($rel, $line, 'source-invalid', "$label.from must name an adapter (github, gitlab or dir); add it");
                continue;
            }
            if (!\in_array($from, self::ADAPTERS, true)) {
                $r->error($rel, $line, 'source-invalid', "$label.from \"$from\" is not a known source adapter; use github, gitlab or dir");
                continue;
            }
            if (!\in_array($from, self::IMPLEMENTED_ADAPTERS, true)) {
                $r->warn($rel, $line, 'source-adapter-unimplemented', "$label.from \"$from\" is accepted but has no adapter yet, so the entry fails at sync; use github, gitlab or dir");
            }
            if ($from === 'dir') {
                if (!\is_string($s['path'] ?? null) || $s['path'] === '') {
                    $r->error($rel, $line, 'source-invalid', "$label.path is required for adapter \"dir\"; add the directory path");
                } elseif (!\is_dir(Fs::abs($s['path'], $this->root))) {
                    $r->warn($rel, $line, 'source-dir-missing', "$label.path \"{$s['path']}\" does not exist, so the source is skipped at sync; fix the path");
                }
                foreach (['url', 'host', 'ref'] as $bad) {
                    if (($s[$bad] ?? null) !== null) {
                        $r->error($rel, $line, 'source-invalid', "$label.$bad is not allowed for adapter \"dir\"; remove it");
                    }
                }
                $id = 'dir|' . ($s['path'] ?? '');
            } elseif (\in_array($from, ['http', 'zip'], true)) {
                if (!\is_string($s['url'] ?? null) || $s['url'] === '') {
                    $r->error($rel, $line, 'source-invalid', "$label.url is required for adapter \"$from\"; add it");
                }
                if (($s['package'] ?? null) !== null) {
                    $r->error($rel, $line, 'source-invalid', "$label.package is not allowed for adapter \"$from\", which identifies the source by url; remove it");
                }
                $id = "$from|" . ($s['url'] ?? '');
            } else {
                if (!\is_string($s['package'] ?? null) || $s['package'] === '') {
                    $r->error($rel, $line, 'source-invalid', "$label.package is required for adapter \"$from\"; add it, e.g. \"acme/skills\"");
                }
                foreach (['url', 'path'] as $bad) {
                    if (($s[$bad] ?? null) !== null) {
                        $r->error($rel, $line, 'source-invalid', "$label.$bad is not allowed for adapter \"$from\"; remove it");
                    }
                }
                $id = "$from|" . ($s['host'] ?? '') . '|' . ($s['package'] ?? '');
            }
            if (isset($seen[$id])) {
                $r->error($rel, $line, 'source-duplicate', "$label duplicates an earlier entry with the same adapter, host and package/path; merge them");
            }
            $seen[$id] = true;
            if (isset($s['ref'])) {
                $ref = $s['ref'];
                if (!\is_string($ref) || $ref === '') {
                    $r->error($rel, $line, 'source-invalid', "$label.ref must be a non-empty string; fix it");
                } elseif ($ref === 'self.version') {
                    $r->error($rel, $line, 'source-invalid', "$label.ref \"self.version\" works only in a vendor's extra.skills.sources; pin a tag or constraint instead");
                } elseif (\preg_match('/^[\^~><=!]|[*|,]|\s-\s|\s/', $ref) === 1
                    && \preg_match('/^([\^~])v?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $ref) !== 1
                    && \preg_match('/^=v?\d+(?:\.\d+){0,3}(?:-[\w.+-]+)?$/', $ref) !== 1) {
                    // Mirrors the plugin's ref resolver: constraint-looking refs must be caret, tilde or exact.
                    $r->error($rel, $line, 'source-ref-unsupported', "$label.ref \"$ref\" is not a supported constraint; use ^1.2.3, ~1.2, =1.2.3 or a literal tag/branch/SHA");
                }
            }
            if (\array_key_exists('skills', $s)) {
                $skills = $s['skills'];
                if (!\is_array($skills) || !\array_is_list($skills) || \in_array(true, \array_map(static fn($x): bool => !\is_string($x) || $x === '', $skills), true)) {
                    $r->error($rel, $line, 'source-invalid', "$label.skills must be a list of skill names; fix it");
                }
            }
        }
    }

    /**
     * Legacy project keys in composer.json extra.skills.
     *
     * @return array<string, mixed>
     */
    private function checkInline(?array $composer, bool $hasSkillsJson): array
    {
        $skills = $composer['extra']['skills'] ?? null;
        if (!\is_array($skills)) {
            return [];
        }
        $keys = \array_values(\array_intersect(\array_keys($skills), self::INLINE_KEYS));
        if ($keys === []) {
            return [];
        }
        $rel = $this->path('composer.json');
        $text = (string) @\file_get_contents($this->root . '/composer.json');
        $line = Fs::jsonKeyLine($text, $keys[0]);
        if ($hasSkillsJson) {
            $this->report->warn($rel, $line, 'inline-config-ignored', 'extra.skills keys ' . \implode(', ', $keys) . ' are ignored because skills.json exists; move them into skills.json and delete them from composer.json');

            return [];
        }
        $this->report->info($rel, $line, 'inline-config-legacy', 'project settings live in the legacy composer.json extra.skills block (' . \implode(', ', $keys) . '); the next composer update or skills:update moves them into skills.json, so no action is needed');
        $inline = \array_intersect_key($skills, \array_flip($keys));
        foreach ($keys as $k) {
            if (isset(self::DEPRECATED[$k])) {
                $this->report->warn($rel, Fs::jsonKeyLine($text, $k), 'config-deprecated-key', "deprecated extra.skills key \"$k\" still works but will be migrated; " . self::DEPRECATED[$k]);
            }
        }
        $this->checkValues($inline, $rel, $text, true);

        return $inline;
    }

    private function checkPluginAllowed(?array $composer): void
    {
        if ($composer === null || ($composer['name'] ?? null) === 'llm/skills') {
            return;
        }
        $required = isset($composer['require']['llm/skills']) || isset($composer['require-dev']['llm/skills']);
        if (!$required) {
            return;
        }
        $allow = $composer['config']['allow-plugins'] ?? null;
        if ($allow === true || (\is_array($allow) && ($allow['llm/skills'] ?? null) === true)) {
            return;
        }
        $wild = \is_array($allow) && \in_array(true, \array_map(static fn($k, $v): bool => $v === true && \fnmatch((string) $k, 'llm/skills'), \array_keys($allow), $allow), true);
        if ($wild) {
            return;
        }
        $this->report->warn($this->path('composer.json'), Fs::jsonKeyLine((string) @\file_get_contents($this->root . '/composer.json'), 'allow-plugins'), 'plugin-not-allowed',
            'llm/skills is required but not allowed as a plugin, so non-interactive installs skip it; add "llm/skills": true under config.allow-plugins');
    }

    /**
     * Runs git in the project root; returns the exit code and fills $out, or null when git cannot start.
     *
     * @param list<string> $args
     */
    private function git(array $args, ?string &$out = null): ?int
    {
        $out = '';
        $proc = @\proc_open(['git', '-C', $this->root, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($proc)) {
            return null;
        }
        $out = (string) \stream_get_contents($pipes[1]);
        \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return \proc_close($proc);
    }

    private function gitReady(): bool
    {
        if ($this->git !== null) {
            return $this->git;
        }
        if ($this->git(['--version']) !== 0) {
            $this->report->info($this->path('.'), null, 'git-unavailable', 'git is not available, so tracking, ignore and export-ignore checks were skipped; install git and run again for them');

            return $this->git = false;
        }
        if ($this->git(['rev-parse', '--is-inside-work-tree'], $out) !== 0 || \trim($out) !== 'true') {
            $this->report->info($this->path('.'), null, 'not-a-git-repo', 'project is not inside a git work tree, so tracking, ignore and export-ignore checks were skipped');

            return $this->git = false;
        }

        return $this->git = true;
    }

    private function tracked(string $rel): bool
    {
        return $this->git(['ls-files', '--', $rel], $out) === 0 && \trim($out) !== '';
    }

    private function ignored(string $rel, bool $dir): bool
    {
        return $this->git(['check-ignore', '-q', '--no-index', '--', $rel . ($dir ? '/' : '')]) === 0;
    }

    private function exportIgnored(string $rel): bool
    {
        $this->git(['check-attr', 'export-ignore', '--', $rel], $out);

        return \str_ends_with(\trim($out), ': set');
    }

    /**
     * @param list<string> $aliases
     */
    private function checkGit(string $target, array $aliases): void
    {
        $r = $this->report;
        // An alias equal to the target is a config error reported elsewhere, not a link to inspect.
        $aliases = \array_values(\array_filter($aliases, static fn(string $a): bool => self::normPath($a) !== self::normPath($target)));
        foreach ($aliases as $alias) {
            $abs = Fs::abs($alias, $this->root);
            if (Fs::isLink($abs)) {
                continue;
            }
            if (\is_dir($abs)) {
                $r->error(Fs::rel($abs), null, 'alias-real-directory', 'alias path is a real directory, so llm/skills refuses to replace it with a link and the sync fails; move its contents into the target and delete it');
            } elseif (\is_file($abs)) {
                $r->warn(Fs::rel($abs), null, 'alias-is-file', 'alias path is a regular file, so llm/skills cannot create the link and the sync fails; remove the file');
            } else {
                $r->info(Fs::rel($abs), null, 'alias-missing', 'alias link does not exist yet; composer skills:update creates it');
            }
        }
        if (!$this->gitReady()) {
            return;
        }
        $t = self::normPath($target);
        if (!Fs::isAbsolute($t) && !\str_starts_with($t, '..')) {
            if ($this->tracked($t)) {
                $r->warn($this->path($t), null, 'target-tracked', "target \"$t\" is tracked by git, so skills pulled in through skills.local.json land in a tracked folder and invite accidental commits; add \"/$t/\" to .gitignore and run git rm -r --cached $t, unless you commit installed skills on purpose");
            } elseif (\file_exists($this->root . '/' . $t) && !$this->ignored($t, true)) {
                $r->info($this->path($t), null, 'target-not-ignored', "target \"$t\" is neither tracked nor ignored, so it shows up as untracked in git status; add \"/$t/\" to .gitignore");
            }
        }
        foreach ($aliases as $alias) {
            $a = self::normPath($alias);
            if (Fs::isAbsolute($a) || \str_starts_with($a, '..')) {
                continue;
            }
            if ($this->tracked($a)) {
                $r->warn($this->path($a), null, 'alias-tracked', "alias \"$a\" is tracked by git, though it is a machine-specific link to the target; add \"/$a\" to .gitignore and run git rm -r --cached $a");
            } elseif (!$this->ignored($a, false) && !$this->ignored($a, true)) {
                $r->warn($this->path($a), null, 'alias-not-ignored', "alias \"$a\" is not git-ignored, so the link would be committed; add \"/$a\" to .gitignore");
            }
        }
        if ($this->tracked('skills.local.json')) {
            $r->warn($this->path('skills.local.json'), null, 'local-config-tracked', 'skills.local.json is committed, though it holds personal settings; run git rm --cached skills.local.json and add "/skills.local.json" to .gitignore');
        } elseif (!$this->ignored('skills.local.json', false)) {
            $r->warn($this->path('.gitignore'), null, 'local-config-not-ignored', 'skills.local.json is not git-ignored, so personal settings could be committed; add "/skills.local.json" to .gitignore');
        }
    }

    /**
     * Package-author checks: declared or auto-discovered skills that ship to consumers.
     */
    private function checkAuthor(?array $composer, string $target): void
    {
        if ($composer === null) {
            return;
        }
        $skillsExtra = $composer['extra']['skills'] ?? null;
        $declared = \is_array($skillsExtra) && \array_key_exists('source', $skillsExtra);
        $isPackage = \is_string($composer['name'] ?? null) && ($composer['type'] ?? 'library') !== 'project';
        if (!$declared && !$isPackage) {
            return;
        }
        $rel = $this->path('composer.json');
        $text = (string) @\file_get_contents($this->root . '/composer.json');
        $hasAttributes = \is_file($this->root . '/.gitattributes');
        $git = $this->gitReady();
        /** @var list<string> $shipped skill directories consumers receive */
        $shipped = [];

        if (\is_array($skillsExtra['sources'] ?? null)) {
            foreach ($skillsExtra['sources'] as $i => $s) {
                if (\is_array($s) && ($s['from'] ?? null) === 'dir') {
                    $this->report->error($rel, Fs::jsonKeyLine($text, 'sources'), 'vendor-source-dir', "extra.skills.sources[$i].from \"dir\" is not allowed in a package; use extra.skills.source for in-package paths");
                }
            }
        }

        if ($declared) {
            $line = Fs::jsonKeyLine($text, 'source');
            $sources = $skillsExtra['source'];
            $list = \is_string($sources) ? [$sources] : $sources;
            if (!\is_array($list) || $list === [] || \in_array(true, \array_map(static fn($s): bool => !\is_string($s) || $s === '', $list), true)) {
                $this->report->error($rel, $line, 'source-invalid', 'extra.skills.source must be a non-empty relative path or a non-empty list of them; fix the value');

                return;
            }
            $seen = [];
            foreach ($list as $src) {
                $n = self::normPath($src);
                if (Fs::isAbsolute($src)) {
                    $this->report->error($rel, $line, 'source-invalid', "extra.skills.source \"$src\" must be a relative path; make it relative to the package root");
                    continue;
                }
                // "." and paths that resolve to the root ("a/..") count as escaping it, as in llm/skills.
                $norm = Fs::normalize($n);
                if ($norm === '.' || $norm === '' || \str_starts_with($norm, '..')) {
                    $this->report->error($rel, $line, 'source-invalid', "extra.skills.source \"$src\" must not escape the package root; point it inside the package");
                    continue;
                }
                if (isset($seen[$n])) {
                    $this->report->error($rel, $line, 'source-invalid', "extra.skills.source lists \"$src\" twice; remove the duplicate");
                    continue;
                }
                $seen[$n] = true;
                $dir = $this->root . '/' . $n;
                if (!\is_dir($dir)) {
                    $this->report->error($rel, $line, 'source-missing', "extra.skills.source \"$src\" does not exist, so consumers get no skills from it; create the directory or fix the path");
                    continue;
                }
                if ($git && $this->exportIgnored($n)) {
                    $this->report->error($this->path($n), null, 'source-export-ignored', 'declared skills source is export-ignored, so dist installs of the package miss it and consumers see "source directory does not exist"; drop export-ignore for this path in .gitattributes');
                } elseif ($git && $hasAttributes) {
                    $this->report->info($this->path($n), null, 'export-ignore-status', 'declared skills source is included in dist archives; no action needed');
                }
                $sourceSkills = $this->sourceSkills($dir);
                if ($sourceSkills !== []) {
                    $this->shippedDirs[] = $dir;
                }
                foreach ($sourceSkills as $skill) {
                    $shipped[] = $skill;
                    $this->inspectSkill($skill);
                }
            }
            foreach (self::CONTAINERS as $c) {
                if (isset($seen[$c]) || !\is_dir($this->root . '/' . $c) || Fs::isLink($this->root . '/' . $c)
                    || ($git && !$this->tracked($c)) || self::containerSkills($this->root . '/' . $c) === []) {
                    continue;
                }
                $this->report->info($this->path($c), null, 'container-not-shipped', 'skills here are not shipped, because a declared extra.skills.source turns off discovery for this package; no action needed unless they should ship');
            }
        } else {
            foreach (self::CONTAINERS as $c) {
                $dir = $this->root . '/' . $c;
                if (!\is_dir($dir) || Fs::isLink($dir)) {
                    continue;
                }
                $skills = self::containerSkills($dir);
                if ($skills === []) {
                    continue;
                }
                $exported = !$git || !$this->exportIgnored($c);
                $isTracked = !$git || $this->tracked($c);
                if ($git && $hasAttributes) {
                    $this->report->info($this->path($c), null, 'export-ignore-status', $exported
                        ? 'skills folder is included in dist archives, so consumers discover these skills; export-ignore it in .gitattributes if they are for development only'
                        : 'skills folder is export-ignored, so dist installs do not ship it; no action needed if these skills are for development only');
                }
                if (!$exported || !$isTracked) {
                    continue;
                }
                if ($c === $target) {
                    $this->report->warn($this->path($c), null, 'target-shipped', 'the sync target is committed and shipped, so consumers discover these installed copies as this package\'s skills; export-ignore or untrack it');
                }
                $this->shippedDirs[] = $dir;
                foreach ($skills as $s) {
                    $shipped[] = $s;
                    $this->inspectSkill($s);
                }
            }
        }

        $groups = [];
        foreach ($shipped as $dir) {
            $groups[Text::collisionKey(\basename($dir))][] = $dir;
        }
        foreach ($groups as $dirs) {
            if (\count($dirs) < 2) {
                continue;
            }
            $spellings = \array_unique(\array_map('basename', $dirs));
            if (\count($spellings) > 1) {
                foreach ($dirs as $d) {
                    $others = \array_map(static fn(string $o): string => '"' . Fs::rel($o) . '"', \array_values(\array_filter($dirs, static fn(string $o): bool => \basename($o) !== \basename($d))));
                    $this->report->error(Fs::rel($d), null, 'name-collision', 'skill directory "' . \basename($d) . '" collides with ' . \implode(', ', $others)
                        . ' when compared case-insensitively/after Unicode NFC, which aborts a consumer sync; rename one of them');
                }
            }
            foreach ($dirs as $d) {
                $copies = \array_values(\array_filter($dirs, static fn(string $o): bool => $o !== $d && \basename($o) === \basename($d)));
                if ($copies === []) {
                    continue;
                }
                $others = \array_map(static fn(string $o): string => '"' . Fs::rel($o) . '"', $copies);
                $this->report->error(Fs::rel($d), null, 'duplicate-shipped-skill', 'skill "' . \basename($d) . '" also ships from ' . \implode(', ', $others)
                    . ', and a consumer sync aborts on duplicate names from one package; export-ignore or untrack the extra copy, or declare extra.skills.source');
            }
        }
    }

    /**
     * Lines printed after the findings: the command that validates the shipped skills.
     *
     * @return list<string>
     */
    public function hints(): array
    {
        if ($this->shippedDirs === []) {
            return [];
        }
        $args = \array_map(
            // No quoting survives every Windows shell, and escapeshellarg() there blanks out
            // "%" and "!"; double quotes at least keep spaces together.
            static fn(string $d): string => match (true) {
                \preg_match('~^[\w./-]+$~', Fs::rel($d)) === 1 => Fs::rel($d),
                \PHP_OS_FAMILY === 'Windows' => '"' . Fs::rel($d) . '"',
                default => \escapeshellarg(Fs::rel($d)),
            },
            \array_values(\array_unique($this->shippedDirs)),
        );

        return [
            'Validate the shipped skills themselves with: composer skills:lint -- ' . \implode(' ', $args),
            '(with the standalone llm/skills binary: skills lint -- ' . \implode(' ', $args) . ')',
        ];
    }

    /**
     * Skill directories of a declared extra.skills.source, which llm/skills copies one
     * subdirectory at a time; reports subdirectories that would ship but are not skills.
     *
     * @return list<string>
     */
    private function sourceSkills(string $dir): array
    {
        $skills = [];
        foreach (Fs::entries($dir) as $entry) {
            $child = "$dir/$entry";
            if (!\is_dir($child)) {
                continue;
            }
            if ($entry[0] === '.') {
                $this->report->info(Fs::rel($child), null, 'hidden-dir-skipped', 'hidden directory in a skills source is never installed by llm/skills; rename it if it is meant to ship');
                continue;
            }
            if (self::skillFile($child) === null) {
                $this->report->warn(Fs::rel($child), null, 'source-subdir-not-skill', 'directory has no SKILL.md, yet llm/skills copies every subdirectory of a declared extra.skills.source as a skill; add a SKILL.md or move it out of the source directory');
                continue;
            }
            $skills[] = Fs::abs($child);
        }

        return $skills;
    }

    /**
     * Reports a skill file named in the wrong case and a skill marked metadata.internal.
     */
    private function inspectSkill(string $skillDir): void
    {
        $file = self::skillFile($skillDir) ?? 'SKILL.md';
        if ($file !== 'SKILL.md') {
            $this->report->warn(Fs::rel("$skillDir/$file"), null, 'skill-md-wrong-case', "file is named \"$file\", which llm/skills does not find on case-sensitive filesystems; rename it to SKILL.md");
        }
        $content = (string) @\file_get_contents("$skillDir/$file");
        $doc = SkillDocument::parse($content);
        // The frontmatter test llm/skills applies: exact "---" lines within the first 4 KiB.
        $head = (string) \preg_replace('/\A\xEF\xBB\xBF/', '', \substr($content, 0, 4096));
        $visible = \preg_match('/\A---\R.*?\R---(\R|$)/s', $head) === 1;
        $frontmatter = $visible ? Yaml::plain($doc->frontmatter) : null;
        $flag = \is_array($frontmatter) && \is_array($frontmatter['metadata'] ?? null) ? ($frontmatter['metadata']['internal'] ?? null) : null;
        if (\is_string($flag) && \in_array(\strtolower(\trim($flag)), ['true', '1'], true)) {
            $this->report->info(Fs::rel($skillDir), null, 'skill-internal', 'skill is marked metadata.internal, so consumers skip it unless their allowlist names it; no action needed if that is intended');
        }
    }

    /**
     * Skill directories in a discovery folder: flat <dir>/<name>/SKILL.md or catalog <dir>/<category>/<name>/SKILL.md.
     *
     * @return list<string>
     */
    private static function containerSkills(string $dir): array
    {
        $out = [];
        foreach (Fs::entries($dir) as $e) {
            $p = "$dir/$e";
            if (!\is_dir($p) || Fs::isLink($p)) {
                continue;
            }
            if (self::skillFile($p) !== null) {
                $out[] = Fs::abs($p);
                continue;
            }
            foreach (Fs::entries($p) as $e2) {
                if (\is_dir("$p/$e2") && !Fs::isLink("$p/$e2") && self::skillFile("$p/$e2") !== null) {
                    $out[] = Fs::abs("$p/$e2");
                }
            }
        }

        return $out;
    }

    /**
     * Name of the skill file in a directory as it is on disk: "SKILL.md", else a differently
     * cased variant; null when there is none. Compares names, since is_file() ignores case
     * on Windows and macOS.
     */
    private static function skillFile(string $dir): ?string
    {
        $variant = null;
        foreach (Fs::entries($dir) as $entry) {
            if (\strcasecmp($entry, 'SKILL.md') !== 0 || !\is_file("$dir/$entry")) {
                continue;
            }
            if ($entry === 'SKILL.md') {
                return $entry;
            }
            $variant ??= $entry;
        }

        return $variant;
    }
}

exit(Cli::run(static function () use ($usage): int {
    [$opts, $rest] = Cli::parse(\array_slice($_SERVER['argv'], 1), ['json', 'help'], ['root']);
    if (isset($opts['help'])) {
        echo $usage;

        return 0;
    }
    if ($rest !== []) {
        throw new UsageError('unexpected argument "' . $rest[0] . '"; pass the project directory as --root=DIR');
    }
    $root = Fs::abs(\is_string($opts['root'] ?? null) ? $opts['root'] : '.');
    if (!\is_dir($root)) {
        throw new UsageError("--root \"$root\" is not a directory");
    }
    $report = new Report();
    $checker = new ProjectChecker($report, $root);
    $checker->run();

    return $report->emit(isset($opts['json']), $checker->hints());
}, $usage));
