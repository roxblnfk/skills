<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

use LLM\Skills\Discovery\Provider\ProviderId;
use LLM\Skills\Info;

/**
 * Loader for the per-provider built-in trust files.
 *
 * Each provider that supports transitive donor discovery (Composer,
 * npm, go) ships an opinionated list of trusted vendor patterns under
 * `resources/trusted-<providerId>.txt`. The registry picks the right
 * file by id, parses each line through that ecosystem's grammar
 * ({@see VendorPattern} for composer, {@see NpmPattern} for npm,
 * {@see GoPattern} for go) into a {@see TrustedVendors}, and falls
 * back to an empty list when the id is unregistered or the file is
 * missing — keeping a future provider opt-in until its own trust file
 * ships.
 *
 * The trust files apply only to **local-provider transitive
 * discoveries**. Direct deps are implicit-trusted because the user
 * typed them; entries in `sources[]` are likewise implicit-trusted as
 * explicit, typed-by-the-user signals of intent.
 *
 * Not annotated `@psalm-immutable`: the loader does filesystem IO.
 * The class is otherwise stateless and safe to share.
 */
final readonly class TrustedVendorRegistry
{
    /**
     * Mapping from provider id (e.g. `composer`, `npm`, `go`) to the
     * filename relative to `resources/`. Adding a new provider's
     * built-in trust list is a one-line change here plus shipping
     * the file in the package.
     *
     * @var array<non-empty-string, non-empty-string>
     */
    private const FILE_BY_PROVIDER = [
        ProviderId::COMPOSER => 'trusted-composer.txt',
        ProviderId::NPM => 'trusted-npm.txt',
        ProviderId::GO => 'trusted-go.txt',
    ];

    /**
     * Load the built-in trust list for `$providerId`. Returns
     * {@see TrustedVendors::empty()} when:
     *
     * - the provider id is not registered (e.g. `github`, which has no
     *   built-in trust list),
     * - the registered file is missing on disk.
     *
     * Throws only when the file exists but cannot be read, or when a
     * line violates the provider's pattern grammar.
     *
     * @param non-empty-string $providerId
     *
     * @psalm-suppress ImpureFunctionCall reading a file shipped with the package is
     *         conceptually pure but psalm cannot prove it
     */
    public function loadForProvider(string $providerId): TrustedVendors
    {
        $relative = self::FILE_BY_PROVIDER[$providerId] ?? null;
        if ($relative === null) {
            return TrustedVendors::empty();
        }

        $path = Info::ROOT_DIR . '/resources/' . $relative;
        if (!\is_file($path)) {
            return TrustedVendors::empty();
        }

        $content = \file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(
                'Failed to read built-in trust list at ' . $path,
            );
        }

        $patterns = [];
        foreach (\explode("\n", $content) as $line) {
            $line = \trim($line);
            if ($line === '' || \str_starts_with($line, '#')) {
                continue;
            }
            $patterns[] = self::parse($providerId, $line);
        }

        return new TrustedVendors($patterns);
    }

    /**
     * Parse one non-empty, non-comment line through the grammar of the
     * given provider. The set of ids handled here mirrors
     * {@see self::FILE_BY_PROVIDER}: only registered providers ever
     * reach parsing.
     *
     * @param non-empty-string $providerId
     * @param non-empty-string $line
     *
     * @psalm-pure
     */
    private static function parse(string $providerId, string $line): TrustPattern
    {
        return match ($providerId) {
            ProviderId::COMPOSER => VendorPattern::fromString($line),
            ProviderId::NPM => NpmPattern::fromString($line),
            ProviderId::GO => GoPattern::fromString($line),
            default => throw new \RuntimeException(
                'No trust-pattern grammar registered for provider "' . $providerId . '"',
            ),
        };
    }
}
