<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Config\TrustedVendors;
use LLM\Skills\Config\VendorPattern;

/**
 * Maps the root package's `extra` array (from the consumer project's
 * `composer.json`) into a typed {@see ProjectConfig}.
 *
 * A missing `extra.skills` block is treated as "all defaults" — that is the
 * expected state for a project freshly adopting `llm/skills`. Anything
 * else that is malformed throws {@see MalformedProjectConfig}, which is
 * not** caught by the sync command: the user owns this file, so loud
 * failure is preferable to silent defaults.
 *
 * @psalm-immutable
 */
final readonly class ProjectConfigMapper
{
    /**
     * @param mixed $extra raw value of root `composer.json` `extra` field
     *
     * @throws MalformedProjectConfig when `extra.skills` is present but invalid
     *
     * @psalm-mutation-free
     */
    public function fromExtra(mixed $extra): ProjectConfig
    {
        if ($extra === null || $extra === []) {
            return ProjectConfig::default();
        }

        if (!\is_array($extra)) {
            throw new MalformedProjectConfig('Root extra must be an object');
        }

        $skills = $extra['skills'] ?? null;
        if ($skills === null) {
            return ProjectConfig::default();
        }
        if (!\is_array($skills)) {
            throw new MalformedProjectConfig('extra.skills must be an object');
        }

        $target = $skills['target'] ?? ProjectConfig::DEFAULT_TARGET;
        if (!\is_string($target) || $target === '') {
            throw new MalformedProjectConfig('extra.skills.target must be a non-empty string');
        }

        $aliases = $this->mapAliases($skills['aliases'] ?? [], $target);

        $trusted = $this->mapTrusted($skills['trusted'] ?? []);

        $replace = $skills['trusted-replace'] ?? false;
        if (!\is_bool($replace)) {
            throw new MalformedProjectConfig('extra.skills.trusted-replace must be a boolean');
        }

        $discovery = $skills['discovery'] ?? false;
        if (!\is_bool($discovery)) {
            throw new MalformedProjectConfig('extra.skills.discovery must be a boolean');
        }

        return new ProjectConfig(
            target: $target,
            trusted: $trusted,
            trustedReplace: $replace,
            discovery: $discovery,
            aliases: $aliases,
        );
    }

    /**
     * Cheap normalisation purely for same-string detection at the config
     * level: forward-slash separators and no trailing slash. Not a path
     * resolver — that's `SyncPlanner`'s job.
     *
     * @psalm-pure
     */
    private static function lexicalNormalise(string $path): string
    {
        return \rtrim(\str_replace('\\', '/', $path), '/');
    }

    /**
     * Validate and return the `aliases` list. Each entry must be a non-empty
     * string. After light lexical normalisation (separator unification,
     * trailing-slash strip) no alias may equal `$target`, and no two aliases
     * may collide.
     *
     * Resolution against the project root happens later in {@see \LLM\Skills\Sync\SyncPlanner};
     * this method only catches the obvious raw-string mistakes. The planner
     * runs a second pass against fully resolved absolute paths, which catches
     * cases like `./.claude/skills` vs `.claude/skills` after both join the
     * project root.
     *
     * @param non-empty-string $target the already-validated target path; used to forbid
     *         `target == alias` configurations up front
     *
     * @return list<non-empty-string>
     *
     * @throws MalformedProjectConfig
     *
     * @psalm-pure
     */
    private function mapAliases(mixed $raw, string $target): array
    {
        if ($raw === []) {
            return [];
        }
        if (!\is_array($raw) || !\array_is_list($raw)) {
            throw new MalformedProjectConfig('extra.skills.aliases must be a list of non-empty strings');
        }

        $normalisedTarget = self::lexicalNormalise($target);

        $out = [];
        $seen = [];
        /** @var int $index */
        foreach ($raw as $index => $value) {
            if (!\is_string($value) || $value === '') {
                throw new MalformedProjectConfig(\sprintf(
                    'extra.skills.aliases[%d] must be a non-empty string',
                    $index,
                ));
            }

            $normalised = self::lexicalNormalise($value);
            if ($normalised === $normalisedTarget) {
                throw new MalformedProjectConfig(\sprintf(
                    'extra.skills.aliases[%d] (%s) cannot equal extra.skills.target',
                    $index,
                    $value,
                ));
            }
            if (isset($seen[$normalised])) {
                throw new MalformedProjectConfig(\sprintf(
                    'extra.skills.aliases[%d] (%s) duplicates an earlier entry',
                    $index,
                    $value,
                ));
            }
            $seen[$normalised] = true;
            $out[] = $value;
        }

        return $out;
    }

    /**
     * @throws MalformedProjectConfig
     *
     * @psalm-mutation-free
     */
    private function mapTrusted(mixed $raw): TrustedVendors
    {
        if (!\is_array($raw)) {
            throw new MalformedProjectConfig('extra.skills.trusted must be a list of patterns');
        }

        $patterns = [];
        /** @var int|string $index */
        foreach ($raw as $index => $value) {
            if (!\is_string($value) || $value === '') {
                throw new MalformedProjectConfig(\sprintf(
                    'extra.skills.trusted[%s] must be a non-empty string',
                    $index,
                ));
            }

            try {
                $patterns[] = VendorPattern::fromString($value);
            } catch (\InvalidArgumentException $e) {
                throw new MalformedProjectConfig(\sprintf(
                    'extra.skills.trusted[%s]: %s',
                    $index,
                    $e->getMessage(),
                ));
            }
        }

        return new TrustedVendors($patterns);
    }
}
