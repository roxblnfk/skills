<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

/**
 * A trust pattern that matches npm package names.
 *
 * Three shapes, mirroring how the npm registry names packages:
 * - `lodash`      — a bare, unscoped package: exact name match.
 * - `@scope/pkg`  — a scoped package: exact name match.
 * - `@scope/*`    — every package published under `@scope`.
 *
 * Matching is literal and case-sensitive. The npm registry lower-cases
 * package names on publish, so a case-insensitive fold would buy nothing and
 * only blur the comparison; keeping it byte-for-byte matches how names appear
 * in a lockfile.
 *
 * Bare wildcards (`*`) and unscoped names containing a slash are rejected:
 * only a scope may carry a wildcard, and an unscoped npm name never contains
 * a slash.
 *
 * @psalm-immutable
 */
final readonly class NpmPattern implements TrustPattern
{
    /**
     * @param non-empty-string $raw    original textual pattern, kept for diagnostics
     * @param non-empty-string|null $scope
     *        the `@scope` segment (leading `@` included) for a scoped pattern;
     *        `null` for a bare, unscoped package
     * @param non-empty-string|null $package
     *        the package segment; `null` means the scope wildcard (`@scope/*`).
     *        For a bare pattern this holds the whole unscoped name.
     *
     * @psalm-mutation-free
     */
    private function __construct(
        public string $raw,
        public ?string $scope,
        public ?string $package,
    ) {}

    /**
     * @param non-empty-string $pattern
     *
     * @throws \InvalidArgumentException when the pattern is not a bare name,
     *         a `@scope/pkg`, or a `@scope/*` wildcard.
     *
     * @psalm-pure
     */
    public static function fromString(string $pattern): self
    {
        if (\str_starts_with($pattern, '@')) {
            $slash = \strpos($pattern, '/');
            if ($slash === false || $slash === \strlen($pattern) - 1) {
                throw new \InvalidArgumentException(\sprintf(
                    'Invalid npm pattern "%s": a scoped pattern needs "@scope/pkg" or "@scope/*".',
                    $pattern,
                ));
            }
            if (\strpos($pattern, '/', $slash + 1) !== false) {
                throw new \InvalidArgumentException(\sprintf(
                    'Invalid npm pattern "%s": expected exactly one "/" after the scope.',
                    $pattern,
                ));
            }

            /** @var non-empty-string $scope the `@` guarantees a non-empty segment */
            $scope = \substr($pattern, 0, $slash);
            /** @var non-empty-string $package */
            $package = \substr($pattern, $slash + 1);

            return new self(
                raw: $pattern,
                scope: $scope,
                package: $package === '*' ? null : $package,
            );
        }

        // Unscoped names are bare identifiers: no slash, no wildcard. A bare
        // `*` would trust the whole registry and is never a valid npm name.
        if (\str_contains($pattern, '/')) {
            throw new \InvalidArgumentException(\sprintf(
                'Invalid npm pattern "%s": an unscoped package name cannot contain "/".',
                $pattern,
            ));
        }
        if ($pattern === '*') {
            throw new \InvalidArgumentException(
                'Invalid npm pattern "*": a bare wildcard is not allowed; scope it as "@scope/*".',
            );
        }

        return new self(raw: $pattern, scope: null, package: $pattern);
    }

    /**
     * @psalm-mutation-free
     */
    #[\Override]
    public function raw(): string
    {
        return $this->raw;
    }

    /**
     * @param non-empty-string $packageName  npm package name (`lodash` or `@scope/pkg`)
     *
     * @psalm-mutation-free
     */
    #[\Override]
    public function matches(string $packageName): bool
    {
        if ($this->scope === null) {
            return $packageName === $this->package;
        }

        if ($this->package === null) {
            return \str_starts_with($packageName, $this->scope . '/');
        }

        return $packageName === $this->scope . '/' . $this->package;
    }
}
