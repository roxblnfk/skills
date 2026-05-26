<?php

declare(strict_types=1);

namespace LLM\Skills\Add;

use Internal\Path;
use LLM\Skills\Config\Mapper\ExternalProjectConfigLoader;
use LLM\Skills\Config\Mapper\ProjectConfigMigrator;
use LLM\Skills\Config\RemoteEntry;

/**
 * Mutates the project's `skills.json` to insert or update a single
 * `remote[]` entry on behalf of `skills:add`.
 *
 * Three guarantees:
 *
 * - **Upsert by composite key** — `(from, host ?? '', package | url)`.
 *   Same key ⇒ overwrite in place; new key ⇒ append.
 * - **Stable sort** — after the upsert the whole `remote[]` is
 *   reordered by composite key so diffs are deterministic across
 *   adds. Manual edits may produce any order in between; the next
 *   add normalises it.
 * - **Atomic write** — payload goes to a sibling temp file then
 *   atomically renames into place. A failed write cannot leave a
 *   half-written `skills.json`.
 *
 * When the file does not exist yet, the writer creates it with the
 * canonical `$schema` pointer (the same one
 * {@see ProjectConfigMigrator} emits). Existing files keep all their
 * other keys verbatim — only `remote[]` is touched.
 */
final readonly class SkillsJsonWriter
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private ExternalProjectConfigLoader $loader = new ExternalProjectConfigLoader(),
    ) {}

    /**
     * Insert or update `$entry` in `<projectRoot>/skills.json`.
     *
     * @throws \RuntimeException when the file cannot be written
     */
    public function upsertRemote(Path $projectRoot, RemoteEntry $entry): void
    {
        /** @psalm-suppress ImpureMethodCall Path::join() is mutation-free */
        $filePath = (string) $projectRoot->join(ExternalProjectConfigLoader::FILE_NAME);

        /** @var array<string, mixed> $payload */
        $payload = $this->loader->load($projectRoot) ?? [];
        // ExternalProjectConfigLoader strips `$schema` — re-add it on
        // write so editors keep the IDE-validation pointer.
        $payload = ['$schema' => ProjectConfigMigrator::SCHEMA_URL] + $payload;

        /** @var mixed $existingRemote */
        $existingRemote = $payload['remote'] ?? [];
        if (!\is_array($existingRemote)) {
            $existingRemote = [];
        }

        $entries = self::normaliseExisting($existingRemote);
        $updated = self::upsertByCompositeKey($entries, $entry);
        $sorted = self::stableSort($updated);

        $payload['remote'] = \array_map(self::serialise(...), $sorted);

        self::atomicWrite($filePath, self::encode($payload));
    }

    /**
     * Normalise existing `remote[]` content into a list of arrays. We
     * keep the loaded shape verbatim — re-parsing through the mapper
     * would be redundant since the file was already validated when it
     * was written.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return list<array<string, mixed>>
     *
     * @psalm-pure
     */
    private static function normaliseExisting(array $raw): array
    {
        $out = [];
        /** @var mixed $entry */
        foreach ($raw as $entry) {
            if (\is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Replace **every** entry that shares the new entry's composite
     * key with a single normalised copy, or append when no match
     * exists. A pre-existing `skills.json` may have been hand-edited
     * to contain duplicate keys (the mapper rejects this on load, but
     * the writer also operates on raw-loaded payloads); collapsing
     * the duplicates here guarantees the post-upsert file always
     * satisfies the composite-key uniqueness constraint, regardless
     * of what shape the file was in before.
     *
     * @param list<array<string, mixed>> $existing
     *
     * @return list<array<string, mixed>>
     *
     * @psalm-pure
     */
    private static function upsertByCompositeKey(array $existing, RemoteEntry $new): array
    {
        $newKey = $new->compositeKey();
        $serialised = self::serialise($new);

        $written = false;
        $out = [];
        foreach ($existing as $entry) {
            if (self::compositeKeyOf($entry) === $newKey) {
                // First match consumes the replacement; subsequent
                // matches drop out entirely so duplicates collapse
                // into a single entry.
                if (!$written) {
                    $out[] = $serialised;
                    $written = true;
                }
                continue;
            }
            $out[] = $entry;
        }
        if (!$written) {
            $out[] = $serialised;
        }
        return $out;
    }

    /**
     * Sort `remote[]` by composite key so diffs are stable across
     * adds. Two entries that share a composite key would have been
     * collapsed by {@see self::upsertByCompositeKey()}; here we just
     * need a deterministic ordering.
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>>
     *
     * @psalm-pure
     */
    private static function stableSort(array $entries): array
    {
        /** @psalm-suppress MixedArgumentTypeCoercion the array entries are well-typed for compositeKeyOf */
        \usort(
            $entries,
            static fn(array $a, array $b): int =>
                self::compositeKeyOf($a) <=> self::compositeKeyOf($b),
        );
        /** @var list<array<string, mixed>> $entries */
        return $entries;
    }

    /**
     * Render an entry as the JSON-serialisable map for storage. Key
     * order is fixed for stable diffs: `from` → `host` (if present) →
     * `package` or `url` → `ref` (if present) → extras.
     *
     * @param RemoteEntry|array<string, mixed> $entry
     *
     * @return array<string, mixed>
     *
     * @psalm-pure
     */
    private static function serialise(RemoteEntry|array $entry): array
    {
        if ($entry instanceof RemoteEntry) {
            $out = ['from' => $entry->from];
            if ($entry->host !== null) {
                $out['host'] = $entry->host;
            }
            if ($entry->package !== null) {
                $out['package'] = $entry->package;
            }
            if ($entry->url !== null) {
                $out['url'] = $entry->url;
            }
            if ($entry->ref !== null) {
                $out['ref'] = $entry->ref;
            }
            /** @var mixed $v */
            foreach ($entry->extras as $k => $v) {
                /** @psalm-suppress MixedAssignment extras carry adapter-specific shapes */
                $out[$k] = $v;
            }
            return $out;
        }
        // Already-serialised array entries (preserving previously
        // stored extras and field order) flow through verbatim.
        return $entry;
    }

    /**
     * Composite key of an already-serialised entry. Mirrors
     * {@see RemoteEntry::compositeKey()} but operates on the raw
     * map form so we never have to round-trip through the mapper.
     *
     * @param array<string, mixed> $entry
     *
     * @psalm-pure
     */
    private static function compositeKeyOf(array $entry): string
    {
        /** @var mixed $from */
        $from = $entry['from'] ?? null;
        /** @var mixed $host */
        $host = $entry['host'] ?? null;
        $fromStr = \is_string($from) ? $from : '';
        $hostStr = \is_string($host) ? $host : '';
        $identifier = '';
        /** @var mixed $package */
        $package = $entry['package'] ?? null;
        if (\is_string($package)) {
            $identifier = $package;
        } else {
            /** @var mixed $url */
            $url = $entry['url'] ?? null;
            if (\is_string($url)) {
                $identifier = $url;
            }
        }
        return $fromStr . '|' . $hostStr . '|' . $identifier;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @psalm-pure
     */
    private static function encode(array $payload): string
    {
        $json = \json_encode(
            $payload,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        if ($json === false) {
            throw new \RuntimeException('failed to encode skills.json payload');
        }
        return $json . "\n";
    }

    /**
     * Write the content into a sibling temp file then rename into
     * place. The rename is atomic on most filesystems (POSIX + NTFS),
     * so a reader can never see a half-written file.
     */
    private static function atomicWrite(string $filePath, string $content): void
    {
        $tmp = $filePath . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
        if (\file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException('failed to write temp skills.json at ' . $tmp);
        }
        if (!@\rename($tmp, $filePath)) {
            @\unlink($tmp);
            throw new \RuntimeException('failed to rename temp skills.json into ' . $filePath);
        }
    }
}
