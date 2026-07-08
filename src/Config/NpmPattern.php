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
     *         a `@scope/pkg`, or a `@scope/*` wildcard. The `*` wildcard is
     *         only ever valid as the whole package segment of a scoped
     *         pattern; a `*` anywhere else — in an unscoped name, in the
     *         scope segment, or embedded in a package segment — is rejected,
     *         since a real npm name never contains one.
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

            $scope = \substr($pattern, 0, $slash);
            $package = \substr($pattern, $slash + 1);

            // `@/pkg` carries a bare `@` with no scope name behind it.
            if ($scope === '@') {
                throw new \InvalidArgumentException(\sprintf(
                    'Invalid npm pattern "%s": the scope segment after "@" must not be empty.',
                    $pattern,
                ));
            }
            // Only the package segment may be a wildcard; a `*` in the scope
            // segment never matches a real npm name.
            if (\str_contains($scope, '*')) {
                throw new \InvalidArgumentException(\sprintf(
                    'Invalid npm pattern "%s": the scope segment cannot contain "*".',
                    $pattern,
                ));
            }
            // The package segment is either the whole wildcard (`*`) or a
            // literal name; a `*` embedded in a name (`*foo`) matches nothing.
            if ($package !== '*' && \str_contains($package, '*')) {
                throw new \InvalidArgumentException(\sprintf(
                    'Invalid npm pattern "%s": the package segment must be "*" or contain no "*".',
                    $pattern,
                ));
            }

            /**
             * @var non-empty-string $scope the `@` plus a non-empty name
             * @var non-empty-string $package
             */
            return new self(
                raw: $pattern,
                scope: $scope,
                package: $package === '*' ? null : $package,
            );
        }

        // Unscoped names are bare identifiers: no slash, no wildcard. Only a
        // scope may carry a wildcard, and an unscoped npm name never contains
        // a slash.
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
        // Any other `*` in an unscoped name matches nothing — the wildcard
        // belongs to a scope (`@scope/*`), never to a bare name.
        if (\str_contains($pattern, '*')) {
            throw new \InvalidArgumentException(\sprintf(
                'Invalid npm pattern "%s": an unscoped package name cannot contain "*"; scope it as "@scope/*".',
                $pattern,
            ));
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
