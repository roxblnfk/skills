<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider\Remote;

use Internal\Path;
use LLM\Skills\Config\RemoteEntry;
use LLM\Skills\Discovery\Provider\ProviderId;
use LLM\Skills\Discovery\Provider\Remote\CachePathBuilder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Unit coverage for the cache-path layout described in spec §7.
 *
 * The paths are deterministic, human-readable enough to grep through
 * a vendor/ tree, and stable across the spec-described inputs. These
 * tests pin the layout so that a future refactor of {@see CachePathBuilder}
 * cannot silently shift cached archives to a new location (which
 * would amount to a one-time mass cache miss for every consumer).
 */
#[Test]
#[Covers(CachePathBuilder::class)]
final class CachePathBuilderTest
{
    public function buildForEntryUsesDefaultHostSegmentWhenAbsent(): void
    {
        $b = new CachePathBuilder();
        $path = $b->buildForEntry(self::root(), self::entry('acme/skills'), 'v1.2.3');

        Assert::true(\str_contains((string) $path, '/cache/github/default/acme__skills/v1.2.3'));
    }

    public function buildForEntryEncodesHostSegment(): void
    {
        $b = new CachePathBuilder();
        $path = $b->buildForEntry(
            self::root(),
            self::entry('team/skills', host: 'https://github.corp.example.com'),
            'v1.0.0',
        );

        // host: scheme stripped, dots normalised to dashes via the
        // safe-set sanitiser.
        $s = (string) $path;
        Assert::true(\str_contains($s, '/cache/github/github.corp.example.com/team__skills/v1.0.0'));
    }

    public function buildForEntryReplacesPackageSlashWithDoubleUnderscore(): void
    {
        $b = new CachePathBuilder();
        $path = $b->buildForEntry(self::root(), self::entry('acme/skills'), 'v1.2.3');

        Assert::true(\str_contains((string) $path, 'acme__skills'));
    }

    public function buildForEntryShortensFullShas(): void
    {
        // A 40-char hex SHA gets shortened to a 12-char prefix so that
        // the cache path stays well under Windows' default 260-char
        // PATH_MAX with realistic project roots.
        $b = new CachePathBuilder();
        $sha = \str_repeat('a', 40);
        $path = $b->buildForEntry(self::root(), self::entry('acme/skills'), $sha);

        Assert::true(\str_contains((string) $path, 'aaaaaaaaaaaa'));
        Assert::false(\str_contains((string) $path, $sha));
    }

    public function buildForEntryPreservesBranchAndTagNamesVerbatim(): void
    {
        $b = new CachePathBuilder();

        $branchPath = $b->buildForEntry(self::root(), self::entry('acme/skills'), 'main');
        Assert::true(\str_ends_with((string) $branchPath, 'main'));

        $tagPath = $b->buildForEntry(self::root(), self::entry('acme/skills'), 'v1.2.3');
        Assert::true(\str_ends_with((string) $tagPath, 'v1.2.3'));
    }

    public function buildForUrlHashesTheUrl(): void
    {
        // Two different URLs must produce different segments. The
        // hash is deterministic — same URL ⇒ same segment.
        $b = new CachePathBuilder();
        $a = (string) $b->buildForUrl(self::root(), 'https://example.com/x.zip', 'v1');
        $bb = (string) $b->buildForUrl(self::root(), 'https://example.com/y.zip', 'v1');

        Assert::true(\str_contains($a, '/cache/url/'));
        Assert::true(\str_contains($bb, '/cache/url/'));
        Assert::notSame($a, $bb, 'different URLs must hash to different segments');
    }

    public function buildForUrlIsDeterministic(): void
    {
        $b = new CachePathBuilder();
        $url = 'https://api.github.com/repos/acme/skills/zipball/v1.2.3';

        Assert::same(
            (string) $b->buildForUrl(self::root(), $url, 'v1.2.3'),
            (string) $b->buildForUrl(self::root(), $url, 'v1.2.3'),
        );
    }

    public function buildForEntryUrlOnlyAdapterUsesHashedSegment(): void
    {
        // The `zip` adapter has no `package`; the cache key falls back
        // to a URL hash, matching the URL-only layout in spec §7.
        $b = new CachePathBuilder();
        $entry = new RemoteEntry(
            from: ProviderId::ZIP,
            package: null,
            url: 'https://example.com/x.zip',
            host: null,
            ref: null,
        );
        $path = $b->buildForEntry(self::root(), $entry, 'sha256-abc');

        // 16 hex chars of sha256(url) sit between zip/<default> and
        // the ref segment.
        Assert::true(\preg_match('#/cache/zip/default/[a-f0-9]{16}/sha256-abc$#', (string) $path) === 1);
    }

    private static function root(): Path
    {
        return Path::create('/some/project');
    }

    /**
     * @param non-empty-string $package
     * @param non-empty-string|null $host
     */
    private static function entry(string $package, ?string $host = null): RemoteEntry
    {
        return new RemoteEntry(
            from: ProviderId::GITHUB,
            package: $package,
            url: null,
            host: $host,
            ref: null,
        );
    }
}
