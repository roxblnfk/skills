#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace LlmSkills\Scripts;

require_once __DIR__ . '/lib/common.php';

$usage = <<<'TXT'
Usage: php scan-skill.php [--json] PATH ...

Deterministic security pre-screen of third-party skills. Flags hidden Unicode,
download-and-execute commands, obfuscated blobs, secret access, persistence writes,
prompt-injection phrasing, binaries, padding, symlinks and pre-approved tools.
It is a pre-screen, not a verdict: read every file before trusting a skill.

PATH  a skill directory, any directory (scanned recursively, .git/ skipped) or a file

Options:
  --json     print findings as a JSON array of {level,file,line,message,code}
  -h, --help show this help

Output: one finding per line "ERROR|WARN|INFO <path>[:<line>]: <message>", then
"N error(s), M warning(s)". Exit code: 0 no errors, 1 errors found, 2 usage error.

TXT;

/**
 * Scans files for patterns that warrant a manual look before a skill is trusted.
 */
final class SkillScanner
{
    private const MAX_PER_CODE = 20;

    private const SCAN_LIMIT = 16 * 1024 * 1024;

    private const BINARY_EXT = [
        'pyc', 'pyo', 'so', 'dll', 'dylib', 'exe', 'bin', 'com', 'msi', 'jar', 'class', 'war', 'ear', 'wasm',
        'o', 'a', 'lib', 'node', 'zip', 'tar', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst', 'lz', 'lzma',
        'cab', 'iso', 'dmg', 'pkg', 'deb', 'rpm', 'apk', 'whl', 'egg', 'docx', 'xlsx', 'pptx', 'docm', 'xlsm',
        'pptm', 'odt', 'ods', 'odp', 'doc', 'xls', 'ppt', 'pdf', 'scr', 'sys', 'elf', 'appimage',
    ];

    /** Media types and their leading bytes; anything else under these extensions is suspicious. */
    private const MEDIA_MAGIC = [
        'png' => ["\x89PNG"], 'jpg' => ["\xFF\xD8\xFF"], 'jpeg' => ["\xFF\xD8\xFF"], 'gif' => ['GIF8'],
        'webp' => ['RIFF'], 'ico' => ["\0\0\1\0"], 'bmp' => ['BM'], 'woff' => ['wOFF'], 'woff2' => ['wOF2'],
        'ttf' => ["\0\1\0\0", 'true'], 'otf' => ['OTTO'], 'mp3' => ['ID3', "\xFF\xFB", "\xFF\xF3", "\xFF\xF2"],
        'wav' => ['RIFF'], 'ogg' => ['OggS'], 'flac' => ['fLaC'], 'mp4' => [], 'mov' => [], 'webm' => ["\x1A\x45\xDF\xA3"],
        'm4a' => [], 'avif' => [], 'tiff' => ["II*\0", "MM\0*"], 'cube' => null, 'icns' => ['icns'],
    ];

    private const EXECUTABLE_MAGIC = ["MZ" => 'a Windows executable', "\x7FELF" => 'an ELF executable',
        "\xCF\xFA\xED\xFE" => 'a Mach-O executable', "\xCA\xFE\xBA\xBE" => 'a Mach-O/Java binary', "PK\x03\x04" => 'a ZIP archive'];

    private const DOCS_HOSTS = '/(?:^|\.)(?:readthedocs\.io|wikipedia\.org|agentskills\.io|json-schema\.org|semver\.org|w3\.org|'
        . 'mozilla\.org|example\.(?:com|org|net)|keepachangelog\.com|conventionalcommits\.org|opensource\.org|'
        . 'creativecommons\.org|schema\.org|php\.net|spdx\.org|commonmark\.org|yaml\.org|ietf\.org|rfc-editor\.org)$'
        . '|^(?:docs|developer|developers|learn|support|help|spec)\./i';

    private const SUSPICIOUS_HOSTS = '/(?:^|\.)(?:bit\.ly|tinyurl\.com|t\.co|goo\.gl|is\.gd|rb\.gy|cutt\.ly|pastebin\.com|paste\.ee|'
        . 'hastebin\.com|ghostbin\.\w+|transfer\.sh|webhook\.site|requestbin\.\w+|pipedream\.net|ngrok(?:-free)?\.\w+|'
        . 'ngrok\.io|trycloudflare\.com|interact\.sh|oast\.\w+|burpcollaborator\.net|api\.telegram\.org|discord(?:app)?\.com)$/i';

    /** @var array<string, int> code => findings reported for the current file */
    private array $perCode = [];

    /** @var array<string, int> code => findings suppressed for the current file */
    private array $suppressed = [];

    public function __construct(private readonly Report $report)
    {
    }

    public function scanPath(string $path): void
    {
        $abs = Fs::abs($path);
        if (!\file_exists($abs) && !\is_link($abs)) {
            $this->report->error(Fs::rel($abs), null, 'path-missing', 'path does not exist; pass a skill directory or file to scan');

            return;
        }
        if (\is_dir($abs) && !Fs::isLink($abs)) {
            if ($this->skipsSelf($abs)) {
                return;
            }
            $this->walk($abs, $abs);
        } else {
            $this->scanFile($abs, \dirname($abs));
        }
    }

    private function walk(string $dir, string $root): void
    {
        foreach (Fs::entries($dir) as $entry) {
            $p = "$dir/$entry";
            if (Fs::isLink($p)) {
                $this->scanFile($p, $root);
                continue;
            }
            if (\is_dir($p)) {
                // The scan root's own repository metadata is not part of the skill; a .git deeper
                // down is copied with the skill, so it is scanned like anything else.
                if ($entry === '.git' && $dir === $root) {
                    $this->report->info(Fs::rel($p), null, 'not-scanned', 'repository metadata at the scan root was not scanned; it does not ship with an installed skill');
                    continue;
                }
                if ($entry[0] === '.') {
                    $this->report->info(Fs::rel($p), null, 'hidden-file', 'hidden directory; agents and installers copy it with the skill, so read its contents too');
                }
                if ($this->skipsSelf($p)) {
                    continue;
                }
                $this->walk($p, $root);
                continue;
            }
            $this->scanFile($p, $root);
        }
    }

    /**
     * The skill holding this scanner describes attacks in its references and lists attack
     * patterns in its code, so scanning it would only report its own tables. The match is by
     * real path of the running script, which another skill cannot claim.
     */
    private function skipsSelf(string $dir): bool
    {
        $self = \realpath(\dirname(__DIR__));
        if ($self === false || \realpath($dir) !== $self) {
            return false;
        }
        $this->report->info(Fs::rel($dir), null, 'self-skipped', 'skipped: this is the skill that ships the scanner, and its pattern tables and attack catalogue would match themselves');

        return true;
    }

    private function scanFile(string $file, string $root): void
    {
        $this->perCode = [];
        $this->suppressed = [];
        $rel = Fs::rel($file);
        try {
            $this->scanFileUnguarded($file, $rel);
        } catch (\Throwable $e) {
            $this->report->error($rel, null, 'scan-failed', 'file could not be scanned (' . $e->getMessage() . '); inspect it manually');
        }
    }

    private function scanFileUnguarded(string $file, string $rel): void
    {
        if (Fs::isLink($file)) {
            $target = @\readlink($file);
            $this->report->warn($rel, null, 'symlink', 'symbolic link or junction' . (\is_string($target) ? " to \"$target\"" : '') . ' can expose files outside the skill; replace it with a real file or remove it');

            return;
        }
        if (!\is_file($file)) {
            $this->report->warn($rel, null, 'special-file', 'entry is not a regular file (FIFO, socket or device), so it was not read; remove it from the skill');

            return;
        }
        $name = \basename($file);
        if ($name[0] === '.') {
            $this->report->info($rel, null, 'hidden-file', 'hidden file; it ships with the skill, so read it too');
        }
        $size = (int) @\filesize($file);
        if ($size > 1024 * 1024) {
            $this->report->warn($rel, null, 'large-file', \sprintf('file is %.1f MB, which is unusual for a skill and can hide content past what scanners read; inspect it manually', $size / 1048576));
        }
        $ext = \strtolower(\pathinfo($name, \PATHINFO_EXTENSION));
        $handle = @\fopen($file, 'rb');
        if ($handle === false) {
            $this->report->warn($rel, null, 'unreadable', 'file cannot be read, so it was not scanned; fix its permissions and scan again');

            return;
        }
        $content = (string) \stream_get_contents($handle, self::SCAN_LIMIT);
        \fclose($handle);

        foreach (self::EXECUTABLE_MAGIC as $magic => $what) {
            // "MZ" also starts ordinary text, so require binary data after it.
            if (\str_starts_with($content, $magic) && ($magic !== 'MZ' || \str_contains(\substr($content, 0, 1024), "\0"))) {
                $this->report->warn($rel, null, 'binary-file', "file content is $what, which cannot be reviewed as text; inspect or remove it before trusting the skill");

                return;
            }
        }
        if (\in_array($ext, self::BINARY_EXT, true)) {
            $this->report->warn($rel, null, 'binary-file', "\".$ext\" file is compiled, archived or a document container that can hide code or instructions; inspect it manually or remove it");

            return;
        }
        if (\array_key_exists($ext, self::MEDIA_MAGIC)) {
            $magics = self::MEDIA_MAGIC[$ext];
            $ok = $magics === null || $magics === [];
            foreach ($magics ?? [] as $m) {
                $ok = $ok || \str_starts_with($content, $m);
            }
            if (!$ok) {
                $this->report->warn($rel, null, 'extension-mismatch', "content does not look like a \".$ext\" file; inspect what it really is");
            }
            if ($magics !== null) {
                return;
            }
        }
        if (\str_contains(\substr($content, 0, 8192), "\0")) {
            $this->report->warn($rel, null, 'binary-file', 'file holds binary data that cannot be reviewed as text; inspect it manually or remove it');

            return;
        }
        if ($size > self::SCAN_LIMIT) {
            $this->report->info($rel, null, 'scan-truncated', 'only the first 16 MB were scanned; inspect the rest manually');
        }

        $this->scanText($content, $rel, $ext);
        if (\strcasecmp($name, 'SKILL.md') === 0) {
            $this->scanFrontmatter($content, $rel);
        }
        foreach ($this->suppressed as $code => $n) {
            $this->report->info($rel, null, 'more-findings', "$n more \"$code\" finding(s) in this file were not listed; search the file for the rest");
        }
    }

    private function hit(string $level, string $rel, ?int $line, string $code, string $message): void
    {
        $this->perCode[$code] = ($this->perCode[$code] ?? 0) + 1;
        if ($this->perCode[$code] > self::MAX_PER_CODE) {
            $this->suppressed[$code] = ($this->suppressed[$code] ?? 0) + 1;

            return;
        }
        $this->report->add($level, $rel, $line, $code, $message);
    }

    private function scanText(string $content, string $rel, string $ext): void
    {
        $utf8 = \preg_match('//u', $content) === 1;
        if (!$utf8) {
            $this->report->info($rel, null, 'invalid-utf8', 'file is not valid UTF-8, so text checks may miss content; convert it to UTF-8 and scan again');
        }
        $lines = \preg_split('/\r\n|\n|\r/', $content) ?: [];
        $hasInvisible = \preg_match('/\xE2\x80[\x8B-\x8F\xAA-\xAE]|\xE2\x81[\xA0-\xA4\xA6-\xA9]|\xEF\xBB\xBF|\xF3\xA0[\x80-\x81][\x80-\xBF]/', $content) === 1;
        $blankRun = 0;
        $blankStart = 0;
        $tools = [];
        $urls = [];
        $isMarkdown = \in_array($ext, ['md', 'markdown', 'mdx', 'txt', ''], true);

        foreach ($lines as $i => $line) {
            $no = $i + 1;
            if (\trim($line) === '') {
                if ($blankRun++ === 0) {
                    $blankStart = $no;
                }
                continue;
            }
            if ($blankRun >= 1000) {
                $this->hit('WARN', $rel, $blankStart, 'blank-padding', "$blankRun consecutive blank lines can push content past what reviewers and scanners read; inspect what follows and remove the padding");
            }
            $blankRun = 0;

            if ($hasInvisible) {
                $this->checkInvisible($line, $rel, $no, $i === 0, $utf8);
            }
            $this->checkExecution($line, $rel, $no);
            $blob = $this->checkBlobs($line, $rel, $no);
            $len = \strlen($line);
            if ($len > 2000 && !$blob) {
                $this->hit('WARN', $rel, $no, 'long-line', "line is $len bytes long and can hide content off-screen; inspect the whole line");
            }
            if (\preg_match('/\S[ \t]{200,}\S/', $line) === 1) {
                $this->hit('WARN', $rel, $no, 'whitespace-padding', 'a long run of spaces pushes text far to the right where reviewers miss it; inspect the end of the line');
            }
            $this->checkSecrets($line, $rel, $no);
            $this->checkPersistence($line, $rel, $no);
            $this->checkInjection($line, $rel, $no);
            if ($isMarkdown && \str_contains($line, '<!--')) {
                $this->hit('WARN', $rel, $no, 'html-comment', 'HTML comment is hidden in rendered Markdown but read by agents; read its full content');
            }

            if (\preg_match_all('/(?<![\w.-])(curl|wget|Invoke-WebRequest|Invoke-RestMethod|iwr|irm|fetch(?=\s*\()|requests\.(?:get|post|put|patch|delete|request|Session)|urllib\.request|http\.client|httpx|axios|XMLHttpRequest|netcat|nc(?=\s+-)|scp|rsync|ftp)(?![\w-])/', $line, $m) > 0) {
                foreach ($m[1] as $tool) {
                    $tools[$tool] ??= [$no, 0];
                    $tools[$tool][1]++;
                }
            }
            if (\preg_match_all('~https?://[^\s<>"\'`)\]\\\\]+~i', $line, $m) > 0) {
                foreach ($m[0] as $url) {
                    $url = \rtrim($url, '.,;:!?*_');
                    $urls[$url] ??= $no;
                }
            }
        }
        if ($blankRun >= 1000) {
            $this->hit('WARN', $rel, $blankStart, 'blank-padding', "$blankRun consecutive blank lines at the end of the file; remove the padding and check nothing was meant to hide behind it");
        }
        foreach ($tools as $tool => [$no, $count]) {
            $this->hit('INFO', $rel, $no, 'network-tool', "uses $tool ($count line(s), first here); confirm every request goes where the skill's purpose needs");
        }
        foreach ($urls as $url => $no) {
            $host = (string) \parse_url($url, \PHP_URL_HOST);
            if ($host === '' || \in_array(\strtolower($host), ['localhost', '127.0.0.1', '0.0.0.0', '[::1]'], true) || \preg_match(self::DOCS_HOSTS, $host) === 1) {
                continue;
            }
            if (\preg_match(self::SUSPICIOUS_HOSTS, $host) === 1 || \preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $host) === 1 || \str_contains($url, '/api/webhooks/')) {
                $this->hit('WARN', $rel, $no, 'suspicious-url', "URL $url points at a raw IP, URL shortener, paste site, tunnel or webhook often used for exfiltration; verify why the skill needs it");
                continue;
            }
            $this->hit('INFO', $rel, $no, 'url', "references $url; confirm the host is expected for this skill");
        }
    }

    private function checkInvisible(string $line, string $rel, int $no, bool $firstLine, bool $utf8): void
    {
        if (!$utf8 || \preg_match('//u', $line) !== 1) {
            if (\preg_match('/\xE2\x80[\x8B-\x8F\xAA-\xAE]|\xE2\x81[\xA0-\xA4\xA6-\xA9]|\xEF\xBB\xBF|\xF3\xA0[\x80-\x81][\x80-\xBF]/', $line) === 1) {
                $this->hit('ERROR', $rel, $no, 'invisible-unicode', 'line holds invisible or bidirectional control characters that can hide instructions; remove them and review the line in a hex viewer');
            }

            return;
        }
        $chars = \preg_split('//u', $line, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $found = [];
        $marks = [];
        $hidden = '';
        $n = \count($chars);
        foreach ($chars as $k => $ch) {
            $cp = self::ord($ch);
            $flag = ($cp >= 0x200B && $cp <= 0x200F) || ($cp >= 0x202A && $cp <= 0x202E) || ($cp >= 0x2060 && $cp <= 0x2064)
                || ($cp >= 0x2066 && $cp <= 0x2069) || ($cp === 0xFEFF && !($firstLine && $k === 0)) || ($cp >= 0xE0000 && $cp <= 0xE007F);
            if (!$flag) {
                continue;
            }
            // A zero-width joiner between two pictographs is part of an emoji sequence.
            if ($cp === 0x200D && $k > 0 && $k < $n - 1 && self::isEmoji(self::ord($chars[$k - 1])) && self::isEmoji(self::ord($chars[$k + 1]))) {
                continue;
            }
            // ZWNJ/ZWJ between letters shape Persian and Indic scripts.
            if (($cp === 0x200C || $cp === 0x200D) && $k > 0 && $k < $n - 1
                && \preg_match('/^[\p{L}\p{M}]$/u', $chars[$k - 1]) === 1 && \preg_match('/^[\p{L}\p{M}]$/u', $chars[$k + 1]) === 1) {
                continue;
            }
            // Directional marks are common in right-to-left text.
            if ($cp === 0x200E || $cp === 0x200F) {
                $marks[\sprintf('U+%04X', $cp)] = true;
                continue;
            }
            $found[\sprintf('U+%04X', $cp)] = true;
            if ($cp >= 0xE0020 && $cp <= 0xE007E) {
                $hidden .= \chr($cp - 0xE0000);
            }
        }
        if ($marks !== []) {
            $this->hit('WARN', $rel, $no, 'bidi-mark', 'line holds directional marks ' . \implode(', ', \array_keys($marks)) . ', normal in right-to-left text but invisible elsewhere; remove them unless the line is RTL text');
        }
        if ($found === []) {
            return;
        }
        $msg = 'line holds invisible/bidirectional characters ' . \implode(', ', \array_keys($found));
        if ($hidden !== '') {
            $msg .= ' encoding hidden text "' . \substr($hidden, 0, 120) . '"';
        }
        $this->hit('ERROR', $rel, $no, 'invisible-unicode', $msg . ' that can hide instructions from a reviewer; remove them and re-read the line');
    }

    private static function ord(string $ch): int
    {
        $b = \array_values(\unpack('C*', $ch) ?: []);

        return match (\count($b)) {
            1 => $b[0],
            2 => (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F),
            3 => (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F),
            default => (($b[0] & 0x07) << 18) | (($b[1] & 0x3F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F),
        };
    }

    private static function isEmoji(int $cp): bool
    {
        return $cp >= 0x1F000 || ($cp >= 0x2600 && $cp <= 0x27BF) || $cp === 0xFE0F || ($cp >= 0x2190 && $cp <= 0x21FF) || $cp === 0x2640 || $cp === 0x2642;
    }

    private function checkExecution(string $line, string $rel, int $no): void
    {
        // In a Markdown table row the cell separators are not shell pipes; an escaped \| inside a cell is.
        if (\preg_match('/^\s*\|.*\|\s*$/', $line) === 1) {
            $line = \str_replace(['|', "\0"], [' ', '|'], \str_replace('\|', "\0", $line));
        }
        $interp = '(?:sudo\s+(?:-\S+\s+)*)?(?:env\s+)?(?:(?:ba|z|da|k|fi|c)?sh|python[0-9.]*|py|perl|ruby|node|php|pwsh|powershell(?:\.exe)?|osascript)\b';
        $rules = [
            ['pipe-to-shell', '/\b(?:curl|wget|iwr|irm|Invoke-WebRequest|Invoke-RestMethod)\b.*\|\s*' . $interp . '/i',
                'downloads content and pipes it straight into an interpreter, the classic malware-install pattern; download the file, read it, then run it explicitly'],
            ['pipe-to-shell', '/(?:\b(?:ba|z|da)?sh\s+-c\s+|\beval\s+|\bsource\s+|(?<![\w.])\.\s+)["\']?[<$]\(\s*(?:curl|wget)\b|\b(?:ba|z|da)?sh\s+<\(\s*(?:curl|wget)\b/i',
                'executes the output of a download directly; download the file, read it, then run it explicitly'],
            ['powershell-exec', '/\|\s*iex\b|\biex\s*[\($]|\bInvoke-Expression\b/i',
                'Invoke-Expression runs a string as PowerShell code, often a downloaded payload; replace it with an explicit, reviewed script'],
            ['powershell-exec', '/\b(?:powershell|pwsh)(?:\.exe)?\b.*\s[-\/](?:e|ec|en|enc|enco|encodedcommand)\s+[A-Za-z0-9+\/=]{8,}/i',
                'runs a base64-encoded PowerShell command that hides what it does; decode it and replace it with readable code'],
            ['decode-exec', '/\b(?:base64\s+(?:-d|--decode|-D)\b.*\|\s*' . $interp . '|(?:eval|exec)\s*\(\s*(?:atob|base64_decode|base64\.b64decode|b64decode|Buffer\.from|gzinflate|str_rot13|codecs\.decode)\b)/i',
                'decodes hidden data and executes it; decode it by hand and review what it runs'],
        ];
        foreach ($rules as [$code, $re, $why]) {
            if (\preg_match($re, $line) === 1) {
                $this->hit('ERROR', $rel, $no, $code, $why);
            }
        }
        if (\preg_match('/(?:npm|pnpm|yarn)\s+config\s+set\s+registry|--(?:extra-)?index-url\s+\S+|pip\S*\s+.*\s-i\s+https?:/i', $line) === 1) {
            $this->hit('WARN', $rel, $no, 'custom-registry', 'switches the package registry, which can install look-alike malicious packages; verify the registry host');
        }
        if (\preg_match('/\bLD_PRELOAD\b|\bDYLD_INSERT_LIBRARIES\b/', $line) === 1) {
            $this->hit('WARN', $rel, $no, 'library-injection', 'LD_PRELOAD/DYLD_INSERT_LIBRARIES injects code into other processes; confirm the skill really needs it');
        }
    }

    private function checkBlobs(string $line, string $rel, int $no): bool
    {
        if (\preg_match('/(?:\\\\x[0-9a-fA-F]{2}){16,}|(?<![0-9a-fA-F])(?:[0-9a-fA-F]{2}){100,}(?![0-9a-fA-F])/', $line) === 1) {
            $this->hit('WARN', $rel, $no, 'hex-blob', 'long hex-encoded blob can hide a payload; decode it and check what it contains');

            return true;
        }
        if (\preg_match('~[A-Za-z0-9+/]{200,}={0,2}~', $line, $m) === 1 && \preg_match('/[0-9+\/]/', $m[0]) === 1) {
            $this->hit('WARN', $rel, $no, 'base64-blob', \sprintf('base64-like blob of %d characters can hide a payload; decode it and check what it contains', \strlen($m[0])));

            return true;
        }

        return false;
    }

    private function checkSecrets(string $line, string $rel, int $no): void
    {
        $rules = [
            'SSH keys' => '#[/\\\\]\.ssh\b|\bid_(?:rsa|ed25519|ecdsa|dsa)\b|\bauthorized_keys\b#i',
            '.env files' => '/(?<![\w.\/-])\.env(?![\w-])/',
            'AWS credentials' => '~\bAWS_[A-Z_]{3,}\b|[/\\\\]\.aws[/\\\\]|\baws\s+configure\s+get\b~',
            'API tokens' => '/\b(?:GITHUB_TOKEN|GH_TOKEN|GITLAB_TOKEN|NPM_TOKEN|NODE_AUTH_TOKEN|PYPI_TOKEN|OPENAI_API_KEY|ANTHROPIC_API_KEY|SLACK_TOKEN|DOCKER_PASSWORD|COMPOSER_AUTH)\b/',
            'stored credentials' => '~\.git-credentials\b|[/\\\\]\.netrc\b|[/\\\\]\.npmrc\b|[/\\\\]\.pypirc\b|\.config[/\\\\]gh\b|\.docker[/\\\\]config\.json|\.kube[/\\\\]config~i',
            'the OS keychain' => '/\bkeychain\b|\bsecurity\s+(?:find|dump)-(?:generic|internet)-password|\bdump-keychain\b|\bsecret-tool\b|\bcmdkey\b|\bCredentialManager\b|\bkwallet/i',
            'browser profiles' => '~Google[/\\\\]Chrome|Chromium[/\\\\]|BraveSoftware|Microsoft[/\\\\]Edge[/\\\\]User Data|\.mozilla[/\\\\]firefox|Firefox[/\\\\]Profiles|\bLogin Data\b|\bcookies\.sqlite\b|\blogins\.json\b|\bkey4\.db\b|\bLocal State\b~i',
            'crypto wallets' => '/\bwallet\.dat\b|\.electrum\b|\bmetamask\b|\bExodus[\/\\\\]|\bseed phrase\b/i',
            'environment dumps' => '/\bprintenv\b|\bos\.environ\b(?!\s*(?:\.get|\[|\.setdefault))|\bprocess\.env\b(?!\s*[.\[])|\bGet-ChildItem\s+env:|\bgci\s+env:|^\s*env\s*(?:\||>|$)/i',
            'agent configuration' => '~\bmcp\.json\b|\.claude\.json\b|\.cursor[/\\\\]mcp~i',
        ];
        foreach ($rules as $what => $re) {
            if (\preg_match($re, $line) === 1) {
                $this->hit('WARN', $rel, $no, 'secret-access', "touches $what; make sure the skill never reads or sends them anywhere");
            }
        }
    }

    private function checkPersistence(string $line, string $rel, int $no): void
    {
        $target = '~(?:\.(?:bashrc|bash_profile|bash_login|zshrc|zprofile|zshenv|zlogin|profile|cshrc|tcshrc)\b|config\.fish\b|Microsoft\.PowerShell_profile\.ps1|\$PROFILE\b'
            . '|\bCLAUDE(?:\.local)?\.md\b|\bAGENTS\.md\b|\bGEMINI\.md\b|\.claude[/\\\\]settings(?:\.local)?\.json|\.claude[/\\\\](?:skills|agents|commands|hooks)\b'
            . '|\.cursor[/\\\\]rules|\.cursorrules|\.github[/\\\\]copilot-instructions\.md|\.git[/\\\\]hooks|/etc/(?:cron|profile|sudoers|rc\.local)|LaunchAgents|LaunchDaemons|\\\\CurrentVersion\\\\Run)~i';
        $write = '/(?:^|\s)>>?\s*\S|\btee\b|\bAdd-Content\b|\bSet-Content\b|\bOut-File\b|\b(?:cp|mv|ln|install|sed\s+-i)\s|file_put_contents|\bwrite(?:File|FileSync|_text|lines)?\s*\(|appendFile|\bopen\s*\([^)]*[\'"][wa]\+?b?[\'"]|\b(?:append|write|insert|inject|overwrite)\w*\b.{0,60}\b(?:to|into|in)\b/i';
        if (\preg_match($target, $line) === 1 && \preg_match($write, $line) === 1) {
            $this->hit('WARN', $rel, $no, 'persistence', 'writes to a shell profile, agent instruction/settings file, git hook or autostart location, which persists beyond this task; confirm the user asked for it');
        }
        if (\preg_match('/\bcrontab\b(?!\s+-l\b)|\bschtasks\s+\/create\b|\bRegister-ScheduledTask\b|\bNew-ScheduledTask|\blaunchctl\s+(?:load|bootstrap|submit)\b|\bsystemctl\s+(?:--user\s+)?enable\b|\bcore\.hooksPath\b|\breg(?:\.exe)?\s+add\s+HK/i', $line) === 1) {
            $this->hit('WARN', $rel, $no, 'persistence', 'installs a scheduled task, service, autostart entry or git hook path that keeps running after the task; confirm the user asked for it');
        }
    }

    private function checkInjection(string $line, string $rel, int $no): void
    {
        $rules = [
            '/\bignore\s+(?:all\s+|any\s+)?(?:of\s+)?(?:the\s+|your\s+)?(?:previous|prior|above|earlier|preceding|former|system)\s+(?:instructions|prompts?|rules|directions|messages|context)/i',
            '/\bdisregard\s+(?:all\s+|any\s+)?(?:the\s+|your\s+)?(?:previous|prior|above|earlier|system|safety)\b/i',
            '/\bforget\s+(?:all\s+|everything\s+)?(?:you\s+(?:were|have\s+been)\s+told|(?:your\s+)?(?:previous|prior)\s+instructions)/i',
            '/\b(?:do\s+not|don[\'’]t|never)\s+(?:tell|inform|notify|alert|warn|show|mention\s+(?:this\s+|it\s+)?to|reveal\s+(?:this\s+|it\s+)?to)\s+(?:the\s+)?user/i',
            '/\bwithout\s+(?:asking|telling|informing|notifying|alerting|confirming|consulting|prompting)\b/i',
            '/\bwithout\s+(?:the\s+)?user[\'’]?s?\s+(?:knowledge|consent|confirmation|approval|permission)/i',
            '/\b(?:disable|bypass|turn\s+off|ignore|skip|override|circumvent|evade)\s+(?:all\s+|any\s+|the\s+|your\s+)?(?:safety|security|guardrails?|sandbox(?:ing)?|content\s+polic(?:y|ies)|permission\s+(?:checks?|prompts?)|confirmations?|restrictions|approval)/i',
            '/--dangerously-[\w-]+|\bbypassPermissions\b|--no-sandbox\b|--yolo\b/i',
            '/\b(?:this|it)\s+(?:is|has\s+been)\s+(?:pre-?approved|already\s+approved|authori[sz]ed\s+by\s+the\s+(?:user|admin))/i',
            '/\byou\s+are\s+now\s+(?:in\s+)?(?:developer\s+mode|DAN\b|jailbr|unrestricted|an?\s+unrestricted|god\s+mode)/i',
            '/<\s*\/?\s*(?:IMPORTANT|SYSTEM|system[-_]prompt|admin|instructions)\s*>|\[\s*SYSTEM\s*\]/',
            '/\bnew\s+(?:system\s+)?instructions\s*:/i',
            '/\b(?:read|load|fetch|follow|download)\s+(?:the\s+)?(?:further\s+|additional\s+|real\s+|full\s+|updated\s+)?instructions\s+(?:from|at|in)\s+(?:https?:|the\s+(?:url|link|archive|zip|attached|docx|pdf))/i',
        ];
        foreach ($rules as $re) {
            if (\preg_match($re, $line, $m) === 1) {
                $this->hit('WARN', $rel, $no, 'prompt-injection', 'phrase "' . \trim($m[0]) . '" reads like an instruction aimed at the agent; check that it serves the skill\'s stated purpose');
            }
        }
    }

    private function scanFrontmatter(string $content, string $rel): void
    {
        $doc = SkillDocument::parse($content);
        if ($doc->frontmatterError !== null) {
            // Agents with lenient parsers may still act on keys a strict parser rejects.
            $this->report->warn($rel, $doc->frontmatterErrorLine, 'frontmatter-unparsed', 'frontmatter could not be parsed (' . $doc->frontmatterError . '), so its keys were checked by pattern only; read the frontmatter by hand');
            foreach (\preg_split('/\r\n|\n|\r/', $content) ?: [] as $i => $line) {
                if ($i > 0 && \rtrim($line) === '---') {
                    break;
                }
                if (\preg_match('/^\s*(hooks|allowed-tools)\s*:(.*)$/', $line, $m) === 1) {
                    $this->report->warn($rel, $i + 1, 'frontmatter-' . ($m[1] === 'hooks' ? 'hooks' : 'allowed-tools'), "frontmatter line declares \"{$m[1]}:" . \rtrim($m[2]) . '"; read what it grants or runs');
                }
            }

            return;
        }
        if ($doc->frontmatter === null || $doc->frontmatter['t'] !== 'map') {
            return;
        }
        $fields = $doc->frontmatter['v'];
        if (isset($fields['allowed-tools'])) {
            $node = $fields['allowed-tools'];
            $value = Yaml::plain($node);
            $tools = [];
            if (\is_array($value)) {
                // Nested lists are flattened so no grant stays hidden.
                \array_walk_recursive($value, static function ($v) use (&$tools): void {
                    $tools[] = (string) $v;
                });
            } else {
                $tools = \preg_split('/[\s,]+(?![^()]*\))/', (string) $value, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            }
            $broad = \array_filter($tools, static fn(string $t): bool => \preg_match('/^(?:Bash|Bash\(\*\)|Bash\(\s*\*?\s*:?\*\)|shell|Write|Edit|WebFetch|\*|PowerShell)$/i', $t) === 1);
            $this->report->info($rel, $node['line'], 'allowed-tools', 'allowed-tools pre-approves: ' . \implode(' ', $tools)
                . ($broad !== [] ? ' (broad grants: ' . \implode(' ', $broad) . ')' : '') . '; make sure each grant fits the skill\'s purpose');
        }
        if (isset($fields['hooks'])) {
            $this->report->warn($rel, $fields['hooks']['line'], 'frontmatter-hooks', 'frontmatter defines hooks, which Claude Code runs as shell commands automatically while the skill is active; read every hook command');
        }
    }
}

exit(Cli::run(static function () use ($usage): int {
    [$opts, $paths] = Cli::parse(\array_slice($_SERVER['argv'], 1), ['json', 'help'], []);
    if (isset($opts['help'])) {
        echo $usage;

        return 0;
    }
    if ($paths === []) {
        throw new UsageError('at least one PATH is required');
    }
    $report = new Report();
    $scanner = new SkillScanner($report);
    foreach ($paths as $path) {
        $scanner->scanPath($path);
    }

    return $report->emit(isset($opts['json']), ['Reminder: a clean scan is not proof of safety; read every file of a third-party skill before trusting it.']);
}, $usage));
