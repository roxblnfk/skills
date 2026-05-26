<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote;

use Internal\Path;
use LLM\Skills\Discovery\Provider\Remote\Http\HttpClient;
use LLM\Skills\Discovery\Provider\Remote\Http\HttpException;

/**
 * {@see RemoteFetcher} that downloads a zip archive over HTTP and
 * extracts it into the project's cache directory.
 *
 * Pipeline:
 *
 * 1. Compute the cache path under
 *    `vendor/llm-skills/cache/<from>/<host>/<package>/<ref>/`
 *    (per spec §7).
 * 2. If the path already exists, return it — the cache is content-
 *    addressed-by-ref, so a hit means we have the right files.
 * 3. Otherwise, GET the URL via {@see HttpClient}, write the bytes
 *    to a temp `.zip` file, extract with {@see \ZipArchive}, locate
 *    the single top-level directory inside the archive (GitHub's
 *    zipball wraps everything in `<owner>-<repo>-<sha>/`), and
 *    rename that directory into the cache location.
 *
 * The fetcher's contract is "given a {@see RemoteDonorRef}, return
 * the path to an extracted Composer-package-shaped directory".
 * Per spec §9.1 any failure (network, corrupt zip, ZipArchive missing,
 * write error) becomes a {@see RemoteFetchException} that the
 * provider turns into a per-ref warning.
 *
 * The constructor does NOT receive a project root — the root is
 * passed at fetch time, which lets a single fetcher instance serve
 * multiple projects when the plugin is reused across sandboxes
 * (tests do this).
 *
 * @psalm-suppress MissingImmutableAnnotation
 *         depends on an impure {@see HttpClient}; filesystem effects are intentional
 */
final readonly class HttpArchiveFetcher implements RemoteFetcher
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private HttpClient $http,
        private Path $projectRoot,
        private CachePathBuilder $cacheBuilder = new CachePathBuilder(),
    ) {}

    #[\Override]
    public function fetch(RemoteDonorRef $ref): Path
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new RemoteFetchException(
                $ref,
                'PHP ZipArchive extension is required to fetch remote archives',
            );
        }

        // The fetcher needs a {@see \LLM\Skills\Config\RemoteEntry} to
        // compute the cache path; we do not have that here. The
        // {@see SkillsJsonRemoteDonorSource} could pass it through a
        // composite VO, but that would couple the fetcher to the
        // source's input shape. Instead we derive a stable cache key
        // from the URL alone — the URL already encodes from/host/ref,
        // so a URL-keyed cache is functionally equivalent and stays
        // independent of the source.
        $cachePath = $this->cacheBuilder->buildForUrl($this->projectRoot, $ref->url, $ref->ref);

        $cachePathStr = (string) $cachePath;
        if (\is_dir($cachePathStr) && \is_file($cachePathStr . '/composer.json')) {
            return $cachePath;
        }

        $tmpZip = $this->downloadToTemp($ref);

        try {
            $extracted = $this->extractZip($ref, $tmpZip);
            $this->moveExtractedToCache($ref, $extracted, $cachePath);
        } finally {
            @\unlink($tmpZip);
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
            $response = $this->http->get($ref->url, [
                'Accept' => 'application/octet-stream',
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

        $tmpPath = \tempnam(\sys_get_temp_dir(), 'llm-skills-archive-');
        if ($tmpPath === false) {
            throw new RemoteFetchException($ref, 'failed to create temp file for archive');
        }
        $tmpPath .= '.zip';

        $bytes = \file_put_contents($tmpPath, $response->body);
        if ($bytes === false) {
            throw new RemoteFetchException(
                $ref,
                'failed to write downloaded archive to ' . $tmpPath,
            );
        }

        /** @var non-empty-string $tmpPath */
        return $tmpPath;
    }

    /**
     * Open the zip with {@see \ZipArchive}, extract to a fresh
     * scratch directory, and return the absolute path of the single
     * top-level directory inside the archive.
     *
     * GitHub zipballs always contain exactly one top-level directory
     * (`<owner>-<repo>-<sha>/`) holding the repo contents. We pick
     * the first directory entry and trust that convention; if the
     * archive is shaped differently (multiple top-level entries or
     * no directory at all) the fetcher errors out so the caller can
     * surface a "malformed archive" warning.
     *
     * @param non-empty-string $tmpZip
     *
     * @return non-empty-string absolute path of the extracted top-level dir
     *
     * @psalm-suppress UndefinedClass,MixedAssignment,MixedMethodCall,MixedArgument
     *         ext-zip is a soft requirement — guarded by class_exists in fetch()
     */
    private function extractZip(RemoteDonorRef $ref, string $tmpZip): string
    {
        $zip = new \ZipArchive();
        $openResult = $zip->open($tmpZip);
        if ($openResult !== true) {
            throw new RemoteFetchException(
                $ref,
                \sprintf('failed to open archive (zip error %d)', $openResult),
            );
        }

        $scratch = \sys_get_temp_dir()
            . '/llm-skills-extract-' . \bin2hex(\random_bytes(6));
        if (!\mkdir($scratch, 0o777, true) && !\is_dir($scratch)) {
            $zip->close();
            throw new RemoteFetchException($ref, 'failed to create scratch dir ' . $scratch);
        }

        $extracted = $zip->extractTo($scratch);
        $zip->close();
        if ($extracted === false) {
            throw new RemoteFetchException($ref, 'failed to extract archive into ' . $scratch);
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
