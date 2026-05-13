<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

/**
 * Immutable set of {@see VendorPattern} entries the project allows skills to be
 * synced from. The effective set is composed at runtime from three sources:
 *
 * builtin ∪ project ∪ --trust=…
 *
 * (or `project ∪ --trust=…` alone when {@see ProjectConfig::$trustedReplace} is
 * `true`).
 *
 * Membership is OR over patterns: a package is trusted if **any** pattern
 * matches it.
 *
 * @psalm-immutable
 */
final readonly class TrustedVendors
{
    /**
     * @param list<VendorPattern> $patterns
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public array $patterns,
    ) {}

    /**
     * @psalm-pure
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Build from raw pattern strings. Useful for loading the built-in list and
     * for short-hand construction in tests. Robust to named-argument calls —
     * the foreach preserves order and rebuilds a list regardless of the input
     * array's keys.
     *
     * @param non-empty-string ...$patterns
     *
     * @throws \InvalidArgumentException when any pattern is malformed
     *
     * @psalm-pure
     */
    public static function fromStrings(string ...$patterns): self
    {
        $result = [];
        foreach ($patterns as $pattern) {
            $result[] = VendorPattern::fromString($pattern);
        }

        return new self($result);
    }

    /**
     * @param non-empty-string $packageName
     *
     * @psalm-mutation-free
     */
    public function trusts(string $packageName): bool
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($packageName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns a new instance with `$other`'s patterns appended. Duplicates are
     * preserved by design — the cost is negligible and keeps merge order
     * traceable for diagnostics.
     *
     * @psalm-mutation-free
     */
    public function merge(self $other): self
    {
        return new self([...$this->patterns, ...$other->patterns]);
    }

    /**
     * Append additional patterns. The foreach rebuilds a list regardless of
     * the input array's keys, so named-argument calls do not break the
     * `list<>` invariant of {@see $patterns}.
     *
     * @psalm-mutation-free
     */
    public function with(VendorPattern ...$patterns): self
    {
        $result = $this->patterns;
        foreach ($patterns as $pattern) {
            $result[] = $pattern;
        }

        return new self($result);
    }
}
