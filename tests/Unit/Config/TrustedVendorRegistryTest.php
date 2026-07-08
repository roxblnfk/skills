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
 * Per-ecosystem trust file, loaded by provider id. The registry must:
 *
 * - Return a populated list for each bundled file (`composer`, `npm`,
 *   `go`), parsed through that ecosystem's grammar.
 * - Return {@see TrustedVendors::empty()} for an unregistered
 *   provider id (e.g. `github`, which ships no trust file).
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
        // No provider-id mapping means no built-in trust — the
        // registry must be opted-in explicitly. An id with no
        // mapping returns an empty list, not an error.
        $vendors = (new TrustedVendorRegistry())->loadForProvider('totally-unknown');

        Assert::same($vendors->patterns, []);
    }

    public function npmProviderParsesBundledFileAndMatchesScopes(): void
    {
        // The shipped resources/trusted-npm.txt parses through the npm
        // grammar (scope wildcards) and matches a package inside a
        // trusted scope while rejecting one outside it.
        $vendors = (new TrustedVendorRegistry())->loadForProvider(ProviderId::NPM);

        Assert::true($vendors->patterns !== [], 'npm trust file should ship at least one pattern');
        Assert::true(
            $vendors->trusts('@anthropic-ai/claude-code'),
            '@anthropic-ai/* must trust a package in the scope',
        );
        Assert::false(
            $vendors->trusts('@evil/x'),
            'an untrusted scope must not be trusted',
        );
        Assert::false(
            $vendors->trusts('lodash'),
            'a bare name absent from the built-in list must not be trusted',
        );
    }

    public function goProviderParsesBundledFileAndMatchesPrefixes(): void
    {
        // The shipped resources/trusted-go.txt parses through the go
        // grammar (module-path prefixes) and matches module paths under
        // trusted orgs while rejecting others.
        $vendors = (new TrustedVendorRegistry())->loadForProvider(ProviderId::GO);

        Assert::true($vendors->patterns !== [], 'go trust file should ship at least one pattern');
        Assert::true(
            $vendors->trusts('github.com/anthropics/anything'),
            'github.com/anthropics/* must trust a module under the org',
        );
        Assert::true(
            $vendors->trusts('github.com/golang/tools'),
            'github.com/golang/* must trust a module under the org',
        );
        Assert::false(
            $vendors->trusts('github.com/evil/x'),
            'an untrusted org must not be trusted',
        );
    }

    public function goWildcardMatchesArbitraryDepthUnderPrefix(): void
    {
        // Prefix (not single-segment) semantics: submodule paths and
        // /vN major-version suffixes are deeper segments, so an
        // org-level entry must reach them. Pins the depth decision.
        $vendors = (new TrustedVendorRegistry())->loadForProvider(ProviderId::GO);

        Assert::true(
            $vendors->trusts('github.com/anthropics/repo/v2'),
            'a /vN major-version path must stay trusted under the org prefix',
        );
        Assert::true(
            $vendors->trusts('github.com/anthropics/repo/internal/tools'),
            'a nested submodule path must stay trusted under the org prefix',
        );
    }

    public function unregisteredProviderWithoutFileReturnsEmpty(): void
    {
        // A locked-vocabulary id that ships no built-in trust file
        // (e.g. a remote-only provider) maps to "no built-in trust" —
        // same effect as a typo.
        $vendors = (new TrustedVendorRegistry())->loadForProvider(ProviderId::GITHUB);

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
                \str_starts_with($pattern->raw(), '#'),
                'comment line leaked into trust list as pattern: ' . $pattern->raw(),
            );
            Assert::notSame(
                $pattern->raw(),
                '',
                'blank line leaked into trust list',
            );
        }
    }
}
