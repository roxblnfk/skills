<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote;

use Internal\Path;
use LLM\Skills\Config\RemoteEntry;

/**
 * Computes deterministic cache paths for fetched remote archives.
 *
 * Layout:
 *
 *     vendor/llm-skills/cache/<from>/<host-segment>/<package-segment>/<ref-segment>/
 *
 * - `<host-segment>` = the literal string `default` when the entry
 *   has no `host`, else a URL-safe encoding of the host. Dots stay
 *   verbatim (they are in the safe set), so `api.github.com` and
 *   `github.corp.example.com` round-trip as-is; only characters
 *   outside `[A-Za-z0-9._-]` are replaced with `-`.
 * - `<package-segment>` = URL-safe encoding of the package
 *   identifier (`/` → `__`, `@` → `at-`).
 * - `<ref-segment>` = the resolved ref (`v1.2.3`, branch name, or
 *   `<sha-12>` for full SHAs).
 *
 * URL-only adapters (`http`, `zip`) use a hash of the URL instead
 * of a package segment:
 *
 *     vendor/llm-skills/cache/<from>/<url-hash>/<ref-segment>/
 *
 * The class is `@psalm-immutable` and every method is `@psalm-pure`
 * — no IO, no state. The path returned is a `Path` value object;
 * the fetcher decides whether to read or write it.
 *
 * @psalm-immutable
 */
final readonly class CachePathBuilder
{
    /**
     * Cache dir under `vendor/`, gitignored by virtue of living in
     * vendor. The layout is exposed as a constant so tests and the
     * fetcher agree on where to look.
     */
    public const VENDOR_CACHE_DIR = 'vendor/llm-skills/cache';

    /**
     * Length of the URL hash segment for URL-only adapters.
     * 16 hex chars (64 bits) is far longer than necessary for
     * uniqueness within a single project but stays human-readable.
     */
    private const URL_HASH_LENGTH = 16;

    /**
     * Length of the full-SHA segment when the resolved ref looks
     * like a 40-char SHA. Shortened to keep paths Windows-friendly
     * (260-char total path limit on default config).
     */
    private const SHA_PREFIX_LENGTH = 12;

    /**
     * Build the cache directory path for the given entry and
     * resolved ref. The directory may not exist yet — the fetcher
     * decides whether to create it. The path is always absolute,
     * rooted at `$projectRoot`.
     *
     * @param non-empty-string $resolvedRef the concrete tag / branch /
     *         SHA the adapter resolved (NOT the stored constraint)
     *
     * @psalm-mutation-free
     */
    public function buildForEntry(Path $projectRoot, RemoteEntry $entry, string $resolvedRef): Path
    {
        $fromSegment = self::encode($entry->from);
        $hostSegment = self::hostSegment($entry->host);
        $refSegment = self::refSegment($resolvedRef);

        if ($entry->url !== null) {
            $idSegment = self::urlHash($entry->url);
        } else {
            $idSegment = self::encode($entry->identifier());
        }

        /** @psalm-suppress ImpureMethodCall Path::join() is mutation-free; psalm conservatism */
        return $projectRoot->join(self::VENDOR_CACHE_DIR)
            ->join($fromSegment)
            ->join($hostSegment)
            ->join($idSegment)
            ->join($refSegment);
    }

    /**
     * URL-only variant: cache key derived purely from a download URL
     * plus a ref label. Used by {@see HttpArchiveFetcher}, which only
     * has the resolved fetch URL (the {@see RemoteEntry} stays with
     * the source layer).
     *
     * Layout:
     *
     *     vendor/llm-skills/cache/url/<url-hash>/<ref-segment>/
     *
     * @param non-empty-string $url
     * @param non-empty-string $resolvedRef
     *
     * @psalm-mutation-free
     */
    public function buildForUrl(Path $projectRoot, string $url, string $resolvedRef): Path
    {
        /** @psalm-suppress ImpureMethodCall Path::join() is mutation-free; psalm conservatism */
        return $projectRoot->join(self::VENDOR_CACHE_DIR)
            ->join('url')
            ->join(self::urlHash($url))
            ->join(self::refSegment($resolvedRef));
    }

    /**
     * URL-safe encode a segment: replace path separators with `__`,
     * scope markers with `at-`, and anything outside the
     * `[A-Za-z0-9._-]` safe set with `-`. The result is
     * round-trippable enough for debugging (you can still recognise
     * the original) without being a one-way hash.
     *
     * @param non-empty-string $segment
     *
     * @return non-empty-string
     *
     * @psalm-pure
     */
    private static function encode(string $segment): string
    {
        $replaced = \strtr($segment, ['/' => '__', '@' => 'at-']);
        $sanitised = (string) \preg_replace('/[^A-Za-z0-9._-]/', '-', $replaced);
        return $sanitised !== '' ? $sanitised : 'segment';
    }

    /**
     * Build the host segment. Absent host → the literal string
     * `default`. Present host → strip the scheme (`https://`,
     * `http://`, …) and any trailing slashes, then sanitise via
     * {@see self::encode()}. Ports are not stripped — `api-github-com-8080`
     * is intentionally a distinct segment from `api-github-com`
     * (different endpoints, different caches).
     *
     * @return non-empty-string
     *
     * @psalm-pure
     */
    private static function hostSegment(?string $host): string
    {
        if ($host === null || $host === '') {
            return 'default';
        }
        /** @var string $stripped */
        $stripped = \preg_replace('#^[a-z]+://#i', '', $host);
        $stripped = \rtrim($stripped, '/');
        if ($stripped === '') {
            return 'default';
        }
        return self::encode($stripped);
    }

    /**
     * Build the ref segment. Shortens 40-char SHAs to 12 chars.
     *
     * @param non-empty-string $ref
     *
     * @return non-empty-string
     *
     * @psalm-pure
     */
    private static function refSegment(string $ref): string
    {
        if (\preg_match('/^[a-f0-9]{40}$/i', $ref) === 1) {
            /** @var non-empty-string $short */
            $short = \substr($ref, 0, self::SHA_PREFIX_LENGTH);
            return $short;
        }
        return self::encode($ref);
    }

    /**
     * Hash a URL into a short, deterministic segment for URL-only
     * adapters where no human-readable package name exists.
     *
     * @param non-empty-string $url
     *
     * @return non-empty-string
     *
     * @psalm-pure
     */
    private static function urlHash(string $url): string
    {
        /** @var non-empty-string $hash */
        $hash = \substr(\hash('sha256', $url), 0, self::URL_HASH_LENGTH);
        return $hash;
    }
}
