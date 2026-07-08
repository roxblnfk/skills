<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Source;

use Internal\Path;
use LLM\Skills\Discovery\Provider\Source\Http\HttpClient;
use LLM\Skills\Discovery\Provider\Source\Http\HttpException;
use LLM\Skills\Unpacker\ArchiveUnpacker;
use LLM\Skills\Unpacker\UnpackerException;
use LLM\Skills\Unpacker\UnpackerFactory;

/**
 * {@see RemoteFetcher} that downloads a zip archive over HTTP and
 * extracts it into the project's cache directory.
 *
 * Pipeline:
 *
 * 1. Compute the cache path via {@see CachePathBuilder::buildForUrl()} —
 *    `vendor/llm-skills/cache/url/<sha256(url)-prefix>/<ref-segment>/`.
 *    The fetcher receives a bare {@see RemoteDonorRef} (URL + ref) and
 *    has no access to the originating `from` / `host` / `package`
 *    triple, so it cannot use the human-readable layout that
 *    {@see CachePathBuilder::buildForEntry()} produces; the URL
 *    already encodes from/host/ref uniquely, so a URL-hash keyed
 *    cache is functionally equivalent.
 * 2. If the path already exists, return it — the cache is content-
 *    addressed-by-ref, so a hit means we have the right files.
 * 3. Otherwise, GET the URL via {@see HttpClient}, write the bytes to
 *    a temp zip file, list every entry name via the selected
 *    {@see ArchiveUnpacker} (zip-slip lexical check on each), extract,
 *    locate the single top-level directory inside the archive
 *    (GitHub's zipball wraps everything in `<owner>-<repo>-<sha>/`),
 *    and rename that directory into the cache location.
 *
 * The unpacker is selected at construction by {@see UnpackerFactory}:
 * `\ZipArchive` when `ext-zip` is loaded, otherwise a CLI fallback
 * (`unzip` / `7z` / `7zz` / `7za`). When neither path is available,
 * `fetch()` fails up front with a single warning listing every tool
 * that was probed — the user knows what to install. Tests can inject
 * a specific unpacker through the constructor.
 *
 * The fetcher's contract is "given a {@see RemoteDonorRef}, return
 * the path to an extracted Composer-package-shaped directory".
 * Any failure (network, corrupt zip, no unpacker, write error)
 * becomes a {@see RemoteFetchException} that the provider turns into
 * a per-ref warning.
 *
 * The project root is bound to the fetcher at construction time
 * (`$projectRoot`) — `fetch()` does not take it as an argument.
 * One fetcher serves one project; a plugin instance reused across
 * sandboxes (the test harness does this) builds a new fetcher per
 * project root.
 *
 * @psalm-suppress MissingImmutableAnnotation
 *         depends on an impure {@see HttpClient}; filesystem effects are intentional
 */
final readonly class HttpArchiveFetcher implements RemoteFetcher
{
    private ?ArchiveUnpacker $unpacker;

    /**
     * @param ArchiveUnpacker|null $unpacker when null, the fetcher autodetects
     *         the best available extractor via {@see UnpackerFactory}. Tests
     *         pass an explicit unpacker to pin the strategy.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        private HttpClient $http,
        private Path $projectRoot,
        private CachePathBuilder $cacheBuilder = new CachePathBuilder(),
        ?ArchiveUnpacker $unpacker = null,
        private UnpackerFactory $unpackerFactory = new UnpackerFactory(),
    ) {
        $this->unpacker = $unpacker;
    }

    /**
     * Lexical zip-slip check on a zip entry name. The name is the
     * archive-relative path; we reject anything that escapes the
     * extraction root through any combination of:
     *
     * - absolute paths (`/foo`, `C:/foo`, `\\server\share`);
     * - parent-directory segments (`../foo`, `foo/../../bar`);
     * - backslash separators on Windows (a forward-slash-only check
     *   is incomplete because `extractTo` happily honours both);
     * - NUL bytes (PHP filesystem APIs truncate at NUL).
     *
     * The check is purely lexical — we do not consult the filesystem
     * — because the archive has not been written yet. Lexical
     * containment is sufficient because the archive contents are not
     * symlinks (ZipArchive does not follow them on extract; CLI tools
     * with `-y` semantics will, but the lexical rejection happens
     * before any byte hits disk).
     *
     * @psalm-pure
     */
    public static function isSafeZipEntryName(string $name): bool
    {
        if ($name === '') {
            return false;
        }
        if (\str_contains($name, "\0")) {
            return false;
        }
        // Normalise separators for the segment walk below; check the
        // unnormalised form for outright absolute paths first.
        if ($name[0] === '/' || $name[0] === '\\') {
            return false;
        }
        // Windows drive-letter absolutes: `C:foo`, `C:/foo`, `C:\foo`.
        if (\preg_match('~^[A-Za-z]:~', $name) === 1) {
            return false;
        }
        $normalised = \strtr($name, ['\\' => '/']);
        foreach (\explode('/', $normalised) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }
        return true;
    }

    #[\Override]
    public function fetch(RemoteDonorRef $ref): Path
    {
        $unpacker = $this->unpacker ?? $this->unpackerFactory->detect();
        if ($unpacker === null) {
            $tools = \implode(', ', \array_merge(['ZipArchive'], UnpackerFactory::reportedCliTools()));
            throw new RemoteFetchException(
                $ref,
                'no archive extractor is available — install ext-zip or one of: '
                . \implode(', ', UnpackerFactory::reportedCliTools())
                . ' (tried: ' . $tools . ')',
            );
        }

        $cachePath = $this->cacheBuilder->buildForUrl($this->projectRoot, $ref->url, $ref->ref);

        $cachePathStr = (string) $cachePath;
        if (\is_dir($cachePathStr) && \is_file($cachePathStr . '/composer.json')) {
            return $cachePath;
        }

        $tmpZip = $this->downloadToTemp($ref);
        $scratch = \sys_get_temp_dir() . '/llm-skills-extract-' . \bin2hex(\random_bytes(6));

        try {
            $extracted = $this->extractZip($ref, $unpacker, $tmpZip, $scratch);
            $this->moveExtractedToCache($ref, $extracted, $cachePath);
        } finally {
            // The scratch dir is per-fetch and unbounded if left behind
            // (sync runs accumulate one entry per fetched ref forever).
            // Clean unconditionally — on success the top-level dir has
            // been moved out and only the empty parent remains; on
            // failure (malformed archive, mid-extract error) it may
            // contain partial content that we also need to remove.
            @\unlink($tmpZip);
            $this->removeRecursive($scratch);
        }

        return $cachePath;
    }

    /**
     * Stream the archive over HTTP into a temp file.
     *
     * @return non-empty-string absolute path of the temp .zip file
     */
    private function downloadToTemp(RemoteDonorRef $ref): string
    {
        try {
            // `Accept: */*` rather than `application/octet-stream`:
            // GitHub's zipball endpoint rejects the latter with HTTP
            // 415 ("Unsupported 'Accept' header") and redirects to
            // `codeload.github.com` on a permissive Accept. Other
            // adapters (raw `http`/`zip` URLs) tolerate `*/*` equally.
            $response = $this->http->get($ref->url, [
                'Accept' => '*/*',
                'User-Agent' => 'llm-skills',
            ]);
        } catch (HttpException $e) {
            $msg = $e->getMessage();
            throw new RemoteFetchException(
                $ref,
                'archive download failed — ' . ($msg !== '' ? $msg : 'transport error'),
                $e,
            );
        }

        if (!$response->isSuccess()) {
            throw new RemoteFetchException(
                $ref,
                \sprintf(
                    'archive download returned HTTP %d for %s',
                    $response->statusCode,
                    $ref->url,
                ),
            );
        }

        // {@see \tempnam()} creates the file at the returned path, so we
        // write directly into it instead of appending a `.zip` suffix
        // (which would orphan the original tempnam file on disk).
        // {@see \ZipArchive::open()} accepts any extension — the suffix
        // is cosmetic.
        $tmpPath = \tempnam(\sys_get_temp_dir(), 'llm-skills-archive-');
        if ($tmpPath === false) {
            throw new RemoteFetchException($ref, 'failed to create temp file for archive');
        }

        $bytes = \file_put_contents($tmpPath, $response->body);
        if ($bytes === false) {
            @\unlink($tmpPath);
            throw new RemoteFetchException(
                $ref,
                'failed to write downloaded archive to ' . $tmpPath,
            );
        }

        /** @var non-empty-string $tmpPath */
        return $tmpPath;
    }

    /**
     * Validate entry names via the unpacker's listing, then extract.
     * Returns the absolute path of the single top-level directory.
     *
     * GitHub zipballs always contain exactly one top-level directory
     * (`<owner>-<repo>-<sha>/`) holding the repo contents. We pick
     * the first directory entry and trust that convention; if the
     * archive is shaped differently (multiple top-level entries or
     * no directory at all) the fetcher errors out so the caller can
     * surface a "malformed archive" warning.
     *
     * @param non-empty-string $tmpZip
     * @param non-empty-string $scratch fresh, ideally not-yet-existing scratch directory
     *         owned by the caller. {@see self::fetch()} cleans it up in `finally`.
     *
     * @return non-empty-string absolute path of the extracted top-level dir
     */
    private function extractZip(
        RemoteDonorRef $ref,
        ArchiveUnpacker $unpacker,
        string $tmpZip,
        string $scratch,
    ): string {
        // Zip-slip guard: validate every entry name BEFORE extraction.
        // The archive comes from a user-configurable {@see SourceEntry::$host}
        // and a single rogue entry like `../../../etc/passwd` or
        // `/etc/passwd` would let the underlying extractor drop files
        // anywhere on disk. Reject anything that does not lexically
        // resolve to a path inside `$scratch`.
        try {
            $names = $unpacker->listEntries($tmpZip);
        } catch (UnpackerException $e) {
            throw new RemoteFetchException(
                $ref,
                'failed to inspect archive — ' . $e->getMessage(),
                $e,
            );
        }

        foreach ($names as $name) {
            if (!self::isSafeZipEntryName($name)) {
                throw new RemoteFetchException(
                    $ref,
                    'archive contains an unsafe entry path "' . $name . '" — refusing to extract',
                );
            }
        }

        if (!\mkdir($scratch, 0o777, true) && !\is_dir($scratch)) {
            throw new RemoteFetchException($ref, 'failed to create scratch dir ' . $scratch);
        }

        try {
            $unpacker->extractTo($tmpZip, $scratch);
        } catch (UnpackerException $e) {
            throw new RemoteFetchException(
                $ref,
                'failed to extract archive — ' . $e->getMessage(),
                $e,
            );
        }

        $topLevel = $this->findSingleTopLevelDir($scratch);
        if ($topLevel === null) {
            throw new RemoteFetchException(
                $ref,
                'archive does not contain a single top-level directory (found in ' . $scratch . ')',
            );
        }

        /** @var non-empty-string $topLevel */
        return $topLevel;
    }

    /**
     * Atomically move the extracted top-level dir into the cache
     * location. If the rename fails (cross-device, parent missing),
     * we fall back to a recursive copy + cleanup so the cache is
     * always populated when the function returns.
     */
    private function moveExtractedToCache(RemoteDonorRef $ref, string $extracted, Path $cachePath): void
    {
        $cachePathStr = (string) $cachePath;
        $cacheParent = \dirname($cachePathStr);
        if (!\is_dir($cacheParent) && !\mkdir($cacheParent, 0o777, true) && !\is_dir($cacheParent)) {
            throw new RemoteFetchException(
                $ref,
                'failed to create cache parent directory ' . $cacheParent,
            );
        }

        // If a stale partial exists, remove it before renaming. We
        // intentionally do NOT preserve user-edited files inside the
        // cache — the cache is regenerable, not user-owned.
        if (\is_dir($cachePathStr)) {
            $this->removeRecursive($cachePathStr);
        }

        if (@\rename($extracted, $cachePathStr)) {
            return;
        }

        // Cross-device or other rename failure — fall back to copy.
        if (!$this->copyRecursive($extracted, $cachePathStr)) {
            throw new RemoteFetchException(
                $ref,
                'failed to populate cache at ' . $cachePathStr,
            );
        }
        $this->removeRecursive($extracted);
    }

    /**
     * Return the single top-level directory inside `$scratch`, or
     * null when the archive shape is unexpected.
     */
    private function findSingleTopLevelDir(string $scratch): ?string
    {
        $entries = @\scandir($scratch);
        if ($entries === false) {
            return null;
        }
        $dirs = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $scratch . '/' . $entry;
            if (\is_dir($full)) {
                $dirs[] = $full;
            }
        }
        if (\count($dirs) !== 1) {
            return null;
        }
        return $dirs[0];
    }

    private function copyRecursive(string $src, string $dst): bool
    {
        if (!\is_dir($src)) {
            return false;
        }
        if (!\is_dir($dst) && !\mkdir($dst, 0o777, true) && !\is_dir($dst)) {
            return false;
        }
        $entries = \scandir($src);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $srcPath = $src . '/' . $entry;
            $dstPath = $dst . '/' . $entry;
            if (\is_dir($srcPath)) {
                if (!$this->copyRecursive($srcPath, $dstPath)) {
                    return false;
                }
            } else {
                if (!\copy($srcPath, $dstPath)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function removeRecursive(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);
            return;
        }
        $entries = @\scandir($path);
        if ($entries === false) {
            @\rmdir($path);
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeRecursive($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
