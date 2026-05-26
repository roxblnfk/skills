<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

/**
 * A trust pattern that matches Composer package names.
 *
 * Two shapes:
 * - `vendor/package` — exact name match
 * - `vendor/*`       — any package in the vendor namespace
 *
 * Bare `vendor` (no slash) is rejected: it is ambiguous and we want users to
 * be explicit about whether they trust the whole vendor or one package.
 *
 * @psalm-immutable
 */
final readonly class VendorPattern
{
    /**
     * @param non-empty-string $raw      original textual pattern, kept for diagnostics
     * @param non-empty-string $vendor   vendor segment before the slash
     * @param non-empty-string|null $package
     *        package segment after the slash; `null` means wildcard (`*`)
     *
     * @psalm-mutation-free
     */
    private function __construct(
        public string $raw,
        public string $vendor,
        public ?string $package,
    ) {}

    /**
     * @param non-empty-string $pattern
     *
     * @throws \InvalidArgumentException when the pattern does not contain a single
     *         `/`, or either side of the slash is empty.
     *
     * @psalm-pure
     */
    public static function fromString(string $pattern): self
    {
        $slash = \strpos($pattern, '/');
        if ($slash === false || $slash === 0 || $slash === \strlen($pattern) - 1) {
            throw new \InvalidArgumentException(\sprintf(
                'Invalid vendor pattern "%s": expected "vendor/package" or "vendor/*".',
                $pattern,
            ));
        }

        // Composer package names contain exactly one slash; treat anything
        // with more as malformed instead of silently letting the extra
        // segments become part of the package name. This also keeps the
        // mapper in sync with `resources/skills.schema.json`, whose
        // `^[^/]+/[^/]+$` regex rejects multi-slash entries.
        if (\strpos($pattern, '/', $slash + 1) !== false) {
            throw new \InvalidArgumentException(\sprintf(
                'Invalid vendor pattern "%s": expected exactly one "/" '
                . '(e.g. "vendor/package" or "vendor/*").',
                $pattern,
            ));
        }

        /** @var non-empty-string $vendor */
        $vendor = \substr($pattern, 0, $slash);
        /** @var non-empty-string $package */
        $package = \substr($pattern, $slash + 1);

        return new self(
            raw: $pattern,
            vendor: $vendor,
            package: $package === '*' ? null : $package,
        );
    }

    /**
     * @param non-empty-string $packageName  full Composer package name (`vendor/package`)
     *
     * @psalm-mutation-free
     */
    public function matches(string $packageName): bool
    {
        $slash = \strpos($packageName, '/');
        if ($slash === false) {
            return false;
        }

        $vendor = \substr($packageName, 0, $slash);
        $package = \substr($packageName, $slash + 1);

        if ($vendor !== $this->vendor) {
            return false;
        }

        return $this->package === null || $this->package === $package;
    }
}
