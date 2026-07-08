<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

/**
 * A single trust pattern for one package-manager ecosystem.
 *
 * Each ecosystem names packages differently, so trust patterns cannot share
 * one grammar. Composer names are `vendor/package` ({@see VendorPattern}),
 * npm packages are bare or `@scope/pkg` ({@see NpmPattern}), and Go modules
 * are slash-separated import paths ({@see GoPattern}). A {@see TrustedVendors}
 * set holds patterns of one ecosystem behind this interface so membership
 * checks stay uniform while each implementation keeps its own grammar and
 * matching semantics.
 *
 * @psalm-immutable
 */
interface TrustPattern
{
    /**
     * Whether `$packageName` is trusted by this pattern. The name is an
     * ecosystem-native package identifier (Composer `vendor/pkg`, npm
     * `@scope/pkg` or bare, Go module path); each implementation compares it
     * against its own grammar.
     *
     * @param non-empty-string $packageName
     *
     * @psalm-mutation-free
     */
    public function matches(string $packageName): bool;

    /**
     * The original textual pattern, kept verbatim for diagnostics.
     *
     * @return non-empty-string
     *
     * @psalm-mutation-free
     */
    public function raw(): string;
}
