<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

use Internal\Path;

/**
 * Configuration declared by a **donor package** under `extra.skills` in its
 * own `composer.json`, paired with the absolute path Composer installed the
 * package at.
 *
 * A donor package is a regular vendor dependency that ships AI skills. The
 * `source` directory is relative to {@see $packageRoot} and contains one
 * subdirectory per skill.
 *
 * Malformed donor configs do **not** abort sync; the mapper throws
 * {@see \LLM\Skills\Config\Exception\MalformedVendorConfig} and the command
 * skips the offending package with a `-v` warning.
 */
final readonly class VendorConfig
{
    /**
     * @param non-empty-string $packageName Composer name, e.g. `acme/skills-pro`
     * @param Path $packageRoot absolute path where Composer installed the package
     * @param non-empty-string $source directory inside the package containing skill subdirs
     * @param bool $discovered `true` when this donor was synthesised by auto-discovery
     *         (the package does not declare `extra.skills`); `false` for declared donors
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $packageName,
        public Path $packageRoot,
        public string $source,
        public bool $discovered = false,
    ) {}

    /**
     * Absolute path to the directory whose immediate subdirectories are skills.
     */
    public function sourcePath(): Path
    {
        return $this->packageRoot->join($this->source);
    }
}
