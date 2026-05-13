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
 * - Package has no `extra.skills` at all → it is not a donor. Use
 * {@see self::declaresSkills()} to detect this before calling
 * {@see self::fromExtra()}; non-donors are skipped **silently**.
 * - Package has `extra.skills` but it is broken → {@see self::fromExtra()}
 * throws {@see MalformedVendorConfig}; the caller emits a `-v` warning and
 * moves on. One bad vendor never blocks the rest of the sync.
 *
 * @psalm-pure
 */
final readonly class VendorConfigMapper
{
    /**
     * Quick predicate: does the package's `extra` declare a `skills` block?
     * Used by the sync command to skip non-donors without producing noise.
     *
     * @psalm-pure
     */
    public static function declaresSkills(mixed $extra): bool
    {
        return \is_array($extra) && isset($extra['skills']);
    }

    /**
     * @param non-empty-string $packageName
     * @param Path             $packageRoot absolute install path of the package
     * @param mixed            $extra       raw value of `composer.json` `extra` field
     *
     * @throws MalformedVendorConfig when `extra.skills` is present but invalid
     *
     * @psalm-pure
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

        return new VendorConfig(
            packageName: $packageName,
            packageRoot: $packageRoot,
            source: $source,
        );
    }
}
