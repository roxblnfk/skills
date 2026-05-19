<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

use Internal\Path;
use LLM\Skills\Config\Exception\MalformedVendorConfig;
use LLM\Skills\Config\VendorConfig;

/**
 * Maps a donor package's `extra` array (as returned by
 * {@see \Composer\Package\PackageInterface::getExtra()}) into a typed
 * {@see VendorConfig}.
 *
 * Two distinct outcomes:
 *
 * - Package has no `extra.skills.source` → it is not a donor. Use
 * {@see self::declaresSkills()} to detect this before calling
 * {@see self::fromExtra()}; non-donors are skipped **silently**. This
 * covers packages that legitimately use `llm/skills` (e.g. set
 * `aliases`, `auto-sync`, or other root-level options in their own
 * `composer.json`) without donating skills of their own.
 * - Package has `extra.skills.source` but the value is broken →
 * {@see self::fromExtra()} throws {@see MalformedVendorConfig}; the
 * caller emits a `-v` warning and moves on. One bad vendor never blocks
 * the rest of the sync.
 */
final readonly class VendorConfigMapper
{
    /**
     * Quick predicate: does the package opt in to being a donor?
     *
     * A package becomes a donor by setting `extra.skills.source`. The
     * mere presence of an `extra.skills` block is not enough — that
     * block may carry only root-level options (`aliases`, `auto-sync`,
     * etc.) that are meaningful when the package is the root project
     * but should be ignored when it is installed as a vendor dependency.
     *
     * @psalm-pure
     */
    public static function declaresSkills(mixed $extra): bool
    {
        if (!\is_array($extra)) {
            return false;
        }

        /** @var mixed $skills */
        $skills = $extra['skills'] ?? null;
        return \is_array($skills) && \array_key_exists('source', $skills);
    }

    /**
     * @param non-empty-string $packageName
     * @param Path $packageRoot absolute install path of the package
     * @param mixed $extra raw value of `composer.json` `extra` field
     *
     * @throws MalformedVendorConfig when `extra.skills` is present but invalid
     */
    public function fromExtra(string $packageName, Path $packageRoot, mixed $extra): VendorConfig
    {
        if (!\is_array($extra)) {
            throw new MalformedVendorConfig($packageName, 'extra must be an object');
        }

        $skills = $extra['skills'] ?? null;
        if (!\is_array($skills)) {
            throw new MalformedVendorConfig($packageName, 'extra.skills must be an object');
        }

        $source = $skills['source'] ?? null;
        if (!\is_string($source) || $source === '') {
            throw new MalformedVendorConfig(
                $packageName,
                'extra.skills.source must be a non-empty string',
            );
        }

        if (Path::create($source)->isAbsolute()) {
            throw new MalformedVendorConfig(
                $packageName,
                'extra.skills.source must be a relative path',
            );
        }

        if (!$packageRoot->join($source)->match($packageRoot->join('*'))) {
            throw new MalformedVendorConfig(
                $packageName,
                'extra.skills.source must not escape the package root',
            );
        }

        return new VendorConfig(
            packageName: $packageName,
            packageRoot: $packageRoot,
            source: $source,
        );
    }
}
