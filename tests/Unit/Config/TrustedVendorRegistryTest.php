<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config;

use LLM\Skills\Config\TrustedVendorRegistry;
use LLM\Skills\Config\TrustedVendors;
use LLM\Skills\Discovery\Provider\ProviderId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Unit coverage for {@see TrustedVendorRegistry}.
 *
 * Spec §8: per-ecosystem trust file, loaded by provider id. The
 * registry must:
 *
 * - Return a populated list for the bundled `composer` file.
 * - Return {@see TrustedVendors::empty()} for an unregistered
 *   provider id (e.g. `npm` until its file ships).
 *
 * We do not exercise the "file exists but read fails" branch —
 * triggering it requires platform-specific chmod tricks that don't
 * pay for the coverage they buy. The error path is straightforward
 * and surfaced by file_get_contents() returning false.
 */
#[Test]
#[Covers(TrustedVendorRegistry::class)]
final class TrustedVendorRegistryTest
{
    public function composerProviderLoadsBundledFile(): void
    {
        // The shipped resources/trusted-composer.txt must produce a
        // non-empty list. Concrete patterns are intentionally not
        // pinned — adding entries to the file should not require a
        // test update.
        $vendors = (new TrustedVendorRegistry())->loadForProvider(ProviderId::COMPOSER);

        Assert::true($vendors->patterns !== [], 'composer trust file should ship at least one pattern');
    }

    public function unknownProviderIdReturnsEmpty(): void
    {
        // Spec §8.5: "no provider-id, no built-in trust — the
        // registry must be opted-in explicitly". An id with no
        // mapping returns an empty list, not an error.
        $vendors = (new TrustedVendorRegistry())->loadForProvider('totally-unknown');

        Assert::same($vendors->patterns, []);
    }

    public function npmProviderReturnsEmptyUntilFileShips(): void
    {
        // npm is locked vocabulary (spec §5.2) but does NOT have a
        // bundled trust file in v1. The registry maps it to "no
        // built-in trust" — same effect as a typo. Pins the
        // forward-compat contract: shipping trusted-npm.txt becomes
        // a purely additive change.
        $vendors = (new TrustedVendorRegistry())->loadForProvider(ProviderId::NPM);

        Assert::same($vendors->patterns, []);
    }

    public function composerFilePatternsAreParsedToVendorPatterns(): void
    {
        // Spot-check that bundled patterns survive parsing into
        // VendorPattern instances. `llm/*` is the project's own
        // namespace and ships in the trust file by definition.
        $vendors = (new TrustedVendorRegistry())->loadForProvider(ProviderId::COMPOSER);

        Assert::true(
            $vendors->trusts('llm/anything'),
            'llm/* must be matched by the bundled trust file',
        );
    }

    public function commentsAndBlankLinesAreStripped(): void
    {
        // The bundled file starts with several `#` comment lines and
        // blank lines. If those leaked through as patterns, the
        // VendorPattern parser would reject them (bare-vendor rule)
        // and the load would throw — pinning the strip behaviour.
        $vendors = (new TrustedVendorRegistry())->loadForProvider(ProviderId::COMPOSER);

        foreach ($vendors->patterns as $pattern) {
            Assert::false(
                \str_starts_with($pattern->raw, '#'),
                'comment line leaked into trust list as pattern: ' . $pattern->raw,
            );
            Assert::notSame(
                $pattern->raw,
                '',
                'blank line leaked into trust list',
            );
        }
    }
}
