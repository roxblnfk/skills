#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace LlmSkills\Scripts;

require_once __DIR__ . '/lib/common.php';

$usage = <<<'TXT'
Usage: php validate-skill.php [--json] [PATH ...]

Validates Agent Skills: SKILL.md presence and frontmatter against the Agent Skills
spec, agent-specific extension keys, body size, links to bundled files, and how the
llm/skills Composer plugin reads the skill.

PATH  a skill directory (holding SKILL.md), a SKILL.md file, or a directory to search
      for skills (vendor/, node_modules/ and .git/ are skipped). Default: "."

Options:
  --json     print findings as a JSON array of {level,file,line,message,code}
  -h, --help show this help

Output: one finding per line "ERROR|WARN|INFO <path>[:<line>]: <message>", then
"N error(s), M warning(s)". Exit code: 0 no errors, 1 errors found, 2 usage error.

TXT;

exit(Cli::run(static function () use ($usage): int {
    [$opts, $paths] = Cli::parse(\array_slice($_SERVER['argv'], 1), ['json', 'help'], []);
    if (isset($opts['help'])) {
        echo $usage;

        return 0;
    }
    $report = new Report();
    $validator = new SkillValidator($report);
    $explicit = $paths !== [];
    foreach ($explicit ? $paths : ['.'] as $path) {
        $validator->validatePath($path, $explicit);
    }
    $validator->reportCollisions();

    return $report->emit(isset($opts['json']));
}, $usage));
