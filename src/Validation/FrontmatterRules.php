<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

use LLM\Skills\Validation\Yaml\NodeKind;
use LLM\Skills\Validation\Yaml\YamlNode;

/**
 * Checks `SKILL.md` frontmatter against the Agent Skills specification, the extension keys
 * some agents read, and how llm/skills reads `name` and `metadata.internal`.
 *
 * @internal
 */
final readonly class FrontmatterRules
{
    public const SPEC_KEYS = ['name', 'description', 'license', 'compatibility', 'metadata', 'allowed-tools'];

    /** Frontmatter keys some agents understand beyond the spec, with the agents that read them. */
    public const EXTENSION_KEYS = [
        'when_to_use' => ['Claude Code'],
        'argument-hint' => ['Claude Code', 'VS Code/Copilot'],
        'arguments' => ['Claude Code'],
        'disable-model-invocation' => ['Claude Code', 'Cursor', 'VS Code/Copilot'],
        'user-invocable' => ['Claude Code', 'VS Code/Copilot'],
        'disallowed-tools' => ['Claude Code'],
        'model' => ['Claude Code'],
        'effort' => ['Claude Code'],
        'context' => ['Claude Code', 'VS Code/Copilot'],
        'agent' => ['Claude Code'],
        'background' => ['Claude Code'],
        'hooks' => ['Claude Code'],
        'paths' => ['Claude Code', 'Cursor'],
        'shell' => ['Claude Code'],
        'globs' => ['Cursor'],
        'icon' => ['Cursor'],
        'color' => ['Cursor'],
    ];

    private const MAX_NAME_LENGTH = 64;
    private const MAX_DESCRIPTION_LENGTH = 1024;
    private const MAX_COMPATIBILITY_LENGTH = 500;

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private FindingCollector $findings,
    ) {}

    /**
     * @param string $skillDir absolute and normalized
     * @param string $file the `SKILL.md` as shown in findings
     */
    public function check(YamlNode $root, string $skillDir, string $file): void
    {
        if ($root->kind !== NodeKind::Map) {
            $this->findings->error($file, $root->line, 'frontmatter-not-mapping', 'SKILL.md frontmatter must be a YAML mapping of "key: value" lines; rewrite it as such');

            return;
        }
        $fields = $root->items;

        $this->checkKeys($fields, $file);
        $this->checkName($fields['name'] ?? null, $root->line, $skillDir, $file);
        $this->checkDescription($fields['description'] ?? null, $root->line, $file);
        $this->checkCompatibility($fields['compatibility'] ?? null, $file);
        $this->checkMetadata($fields['metadata'] ?? null, $file);

        $license = $fields['license'] ?? null;
        if ($license !== null && $license->kind !== NodeKind::Scalar) {
            $this->findings->warn($file, $license->line, 'license-not-string', 'field "license" should be a short string such as "MIT" or "Proprietary. LICENSE.txt has complete terms"; rewrite it as one');
        }

        $tools = $fields['allowed-tools'] ?? null;
        if ($tools !== null && $tools->kind === NodeKind::List) {
            $this->findings->warn($file, $tools->line, 'allowed-tools-list', 'allowed-tools is a YAML list, which only some agents accept; the spec form is one space-separated string such as "Bash(git:*) Read"');
        } elseif ($tools !== null && $tools->kind === NodeKind::Map) {
            $this->findings->error($file, $tools->line, 'allowed-tools-invalid', 'allowed-tools must be a space-separated string of tool names; rewrite it as e.g. "Bash(git:*) Read"');
        }
    }

    /**
     * @param array<array-key, YamlNode> $fields
     */
    private function checkKeys(array $fields, string $file): void
    {
        $known = [...self::SPEC_KEYS, ...\array_keys(self::EXTENSION_KEYS)];
        foreach ($fields as $key => $node) {
            $key = (string) $key;
            if (\in_array($key, self::SPEC_KEYS, true)) {
                continue;
            }
            if ($key === 'internal') {
                $this->findings->warn($file, $node->line, 'internal-top-level', 'top-level "internal" is ignored by llm/skills and rejected by skills-ref; move it under metadata: as "internal: true"');
                continue;
            }
            if (isset(self::EXTENSION_KEYS[$key])) {
                $agents = self::EXTENSION_KEYS[$key];
                $this->findings->warn($file, $node->line, 'extension-key', \sprintf(
                    '"%s" is a %s extension, not an Agent Skills spec field, so other agents ignore it and skills-ref/claude.ai upload reject it; keep it only if you target %s',
                    $key,
                    \implode(' / ', $agents),
                    \count($agents) > 1 ? 'those agents' : 'that agent',
                ));
                continue;
            }
            $guess = Text::nearest($key, $known);
            $this->findings->warn($file, $node->line, 'unknown-key', $guess !== null
                ? "unknown frontmatter key \"$key\" (possible typo of \"$guess\"); rename it, since agents silently ignore unknown keys and skills-ref rejects them"
                : "unknown frontmatter key \"$key\" is ignored by agents and rejected by skills-ref; remove it or move it under metadata:");
        }
    }

    private function checkName(?YamlNode $node, int $rootLine, string $skillDir, string $file): void
    {
        $dirName = PathDisplay::name($skillDir);
        if ($node === null) {
            $this->findings->error($file, $rootLine, 'name-missing', 'missing required field "name"; add "name: ' . $dirName . '" matching the directory name');

            return;
        }
        if (!$node->isFilledScalar()) {
            $this->findings->error($file, $node->line, 'name-empty', 'field "name" must be a non-empty string; set it to the directory name "' . $dirName . '"');

            return;
        }

        $value = Text::nfkc(\trim($node->scalar));
        $line = $node->line;
        $len = Text::length($value);
        if ($len > self::MAX_NAME_LENGTH) {
            $this->findings->error($file, $line, 'name-too-long', "skill name exceeds the 64 character limit ($len chars); shorten it and rename the directory to match");
        }
        if (Text::lower($value) !== $value) {
            $suggest = \trim((string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower($value)), '-');
            $this->findings->error($file, $line, 'name-not-lowercase', "skill name \"$value\" must be lowercase; use e.g. \"$suggest\" and rename the directory to match");
        }
        if (\str_starts_with($value, '-') || \str_ends_with($value, '-')) {
            $this->findings->error($file, $line, 'name-hyphen-edge', "skill name \"$value\" cannot start or end with a hyphen; remove the leading/trailing hyphen");
        }
        if (\str_contains($value, '--')) {
            $this->findings->error($file, $line, 'name-double-hyphen', "skill name \"$value\" cannot contain consecutive hyphens; use a single hyphen");
        }
        if (\preg_match('/[^a-z0-9A-Z-]/u', $value) === 1) {
            $this->findings->error($file, $line, 'name-invalid-chars', "skill name \"$value\" contains invalid characters; use only lowercase letters a-z, digits and hyphens");
        }
        if (Text::nfkc($dirName) !== $value) {
            // llm/skills installs a repository or package root skill under its frontmatter name.
            if (\file_exists("$skillDir/composer.json") || \file_exists("$skillDir/.git")) {
                $this->findings->warn($file, $line, 'name-dir-mismatch', "directory name \"$dirName\" differs from skill name \"$value\"; llm/skills installs this repository-root skill as \"$value\", but other agents expect them to match, so rename one if the skill is also used in place");
            } else {
                $this->findings->error($file, $line, 'name-dir-mismatch', "directory name \"$dirName\" must match skill name \"$value\"; rename the directory or change the name");
            }
        }
        foreach (['anthropic', 'claude'] as $word) {
            if (\str_contains(Text::lower($value), $word)) {
                $this->findings->warn($file, $line, 'name-reserved-word', "skill name contains the reserved word \"$word\", which claude.ai and the Claude API reject on upload; pick a name without it if you publish there");
            }
        }
    }

    private function checkDescription(?YamlNode $node, int $rootLine, string $file): void
    {
        if ($node === null) {
            $this->findings->error($file, $rootLine, 'description-missing', 'missing required field "description"; add one saying what the skill does and when to use it');

            return;
        }
        if (!$node->isFilledScalar()) {
            $this->findings->error($file, $node->line, 'description-empty', 'field "description" must be a non-empty string; describe what the skill does and when to use it');

            return;
        }
        $len = Text::length($node->scalar);
        if ($len > self::MAX_DESCRIPTION_LENGTH) {
            $this->findings->error($file, $node->line, 'description-too-long', "description exceeds the 1024 character limit ($len chars); shorten it and move detail into the body");
        }
        if (\strpbrk($node->scalar, '<>') !== false) {
            $this->findings->warn($file, $node->line, 'description-angle-brackets', 'description contains "<" or ">", which Anthropic packaging rejects and Claude Code shows escaped; rephrase without angle brackets');
        }
    }

    private function checkCompatibility(?YamlNode $node, string $file): void
    {
        if ($node === null) {
            return;
        }
        if ($node->kind !== NodeKind::Scalar) {
            $this->findings->error($file, $node->line, 'compatibility-not-string', 'field "compatibility" must be a string; write the environment requirements as one sentence');
        } elseif (!$node->isFilledScalar()) {
            $this->findings->error($file, $node->line, 'compatibility-empty', 'field "compatibility" must not be empty; remove it or state the environment requirements');
        } elseif (($len = Text::length($node->scalar)) > self::MAX_COMPATIBILITY_LENGTH) {
            $this->findings->error($file, $node->line, 'compatibility-too-long', "compatibility exceeds the 500 character limit ($len chars); shorten it");
        }
    }

    private function checkMetadata(?YamlNode $node, string $file): void
    {
        if ($node === null || $node->isNull()) {
            return;
        }
        if ($node->kind !== NodeKind::Map) {
            $hint = $node->kind === NodeKind::Scalar && \preg_match('/^[\w-]+:\S/', $node->scalar) === 1
                ? ' (add a space after the colon in "' . $node->scalar . '")'
                : '';
            $this->findings->error($file, $node->line, 'metadata-not-mapping', 'field "metadata" must be a mapping of string keys to string values; write it as indented "key: value" lines' . $hint);

            return;
        }
        foreach ($node->items as $key => $value) {
            if ($value->kind !== NodeKind::Scalar) {
                $this->findings->warn($file, $value->line, 'metadata-value-not-string', "metadata.$key holds a nested " . ($value->kind === NodeKind::Map ? 'mapping' : 'list') . ', but metadata values must be strings; flatten it to a string');
            }
        }
        $flag = $node->items['internal'] ?? null;
        if ($flag === null) {
            return;
        }
        $value = $flag->kind === NodeKind::Scalar ? \strtolower(\trim($flag->scalar)) : '';
        if (!\in_array($value, ['true', '1', 'false', '0'], true)) {
            $this->findings->warn($file, $flag->line, 'internal-value-ignored', 'llm/skills treats metadata.internal as set only when it is true or 1; write "internal: true" or remove it');
        }
    }
}
