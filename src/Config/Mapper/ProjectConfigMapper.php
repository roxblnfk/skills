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

        $trusted = $this->mapTrusted($skills['trusted'] ?? []);

        $replace = $skills['trustedReplace'] ?? false;
        if (!\is_bool($replace)) {
            throw new MalformedProjectConfig('extra.skills.trustedReplace must be a boolean');
        }

        return new ProjectConfig(
            target: $target,
            trusted: $trusted,
            trustedReplace: $replace,
        );
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
