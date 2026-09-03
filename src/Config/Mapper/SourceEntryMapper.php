<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

use LLM\Skills\Config\Exception\MalformedSourceEntry;
use LLM\Skills\Config\SourceEntry;
use LLM\Skills\Discovery\Provider\ProviderId;
use LLM\Skills\Discovery\Provider\Source\RefResolver;

/**
 * Maps raw JSON `sources[]` entries into typed {@see SourceEntry} values.
 *
 * Shared between the two config surfaces that declare donor sources:
 *
 * - the consumer project's `skills.json` / inline `extra.skills`
 *   ({@see ProjectConfigMapper}), where a broken entry is fatal;
 * - a vendor package's `extra.skills.sources`
 *   ({@see VendorConfigMapper}), where a broken entry only skips the
 *   offending donor.
 *
 * The mapper itself is policy-free: every failure is a
 * {@see MalformedSourceEntry} carrying the caller-supplied field path,
 * and each caller rethrows it as its own fatal / non-fatal exception
 * type. Surface-specific rules (rejecting `dir` in vendor packages,
 * rejecting `self.version` in project config) also live in the callers —
 * this class validates only what a `sources[]` entry means everywhere.
 *
 * @psalm-immutable
 */
final readonly class SourceEntryMapper
{
    /**
     * Parse and validate a `sources[]` list. Each entry is structurally
     * an object with a mandatory `from` (adapter id), an identifier
     * matching the adapter kind (`path` for dir, `url` for URL-only,
     * `package` otherwise), and optional `host` / `ref` plus
     * adapter-specific extras. Composite uniqueness on
     * `(from, host, path|package|url)` is enforced inside this method so
     * the caller does not have to.
     *
     * @param non-empty-string $field rendered path of the list for error messages,
     *        e.g. `skills.json: sources` or `extra.skills.sources`
     *
     * @return list<SourceEntry>
     *
     * @throws MalformedSourceEntry
     *
     * @psalm-pure
     */
    public static function mapList(mixed $raw, string $field): array
    {
        if ($raw === [] || $raw === null) {
            return [];
        }
        if (!\is_array($raw) || !\array_is_list($raw)) {
            throw new MalformedSourceEntry(
                $field . ' must be a list of objects',
            );
        }

        $out = [];
        $seen = [];
        /**
         * @var int $index
         * @var mixed $entry
         */
        foreach ($raw as $index => $entry) {
            $parsed = self::mapEntry($entry, $field . '[' . $index . ']');

            $compositeKey = $parsed->compositeKey();
            if (isset($seen[$compositeKey])) {
                throw new MalformedSourceEntry(\sprintf(
                    '%s[%d] duplicates an earlier entry (composite key: %s)',
                    $field,
                    $index,
                    $compositeKey,
                ));
            }
            $seen[$compositeKey] = true;
            $out[] = $parsed;
        }

        return $out;
    }

    /**
     * @param non-empty-string $field rendered path of the entry for error messages,
     *        e.g. `skills.json: sources[0]`
     *
     * @throws MalformedSourceEntry
     *
     * @psalm-pure
     */
    public static function mapEntry(mixed $entry, string $field): SourceEntry
    {
        if (!\is_array($entry) || \array_is_list($entry)) {
            throw new MalformedSourceEntry($field . ' must be an object');
        }

        /** @var mixed $rawFrom */
        $rawFrom = $entry['from'] ?? null;
        if (!\is_string($rawFrom) || $rawFrom === '') {
            throw new MalformedSourceEntry(
                $field . '.from must be a non-empty string',
            );
        }
        if (!ProviderId::isKnownSource($rawFrom)) {
            throw new MalformedSourceEntry(\sprintf(
                '%s.from "%s" is not a known source adapter (known: %s)',
                $field,
                $rawFrom,
                \implode(', ', ProviderId::SOURCE_IDS),
            ));
        }
        /** @var non-empty-string $from */
        $from = $rawFrom;

        $package = self::optionalNonEmptyString($entry['package'] ?? null, $field, 'package');
        $url = self::optionalNonEmptyString($entry['url'] ?? null, $field, 'url');
        $host = self::optionalNonEmptyString($entry['host'] ?? null, $field, 'host');
        $ref = self::optionalNonEmptyString($entry['ref'] ?? null, $field, 'ref');
        $path = self::optionalNonEmptyString($entry['path'] ?? null, $field, 'path');

        // Identifier rules by adapter kind: path-only adapters (dir)
        // take `path`; URL-only adapters take `url`; name-based adapters
        // take `package`. The identifier must be the one the adapter
        // expects — a typo (e.g. `package` on a `zip` entry, or `url`
        // on a `dir` entry) is a silent footgun if we accept it, so it
        // surfaces as a load-time error instead.
        if (ProviderId::isPathOnlySource($from)) {
            // `path` is the identifier; `package` stays optional as a
            // donor-name override; `url`/`host`/`ref` are meaningless.
            if ($path === null) {
                throw new MalformedSourceEntry(
                    $field . '.path is required for adapter "' . $from . '"',
                );
            }
            if ($url !== null) {
                throw new MalformedSourceEntry(
                    $field . '.url is not allowed for adapter "' . $from . '" (use path)',
                );
            }
            if ($host !== null) {
                throw new MalformedSourceEntry(
                    $field . '.host is not allowed for adapter "' . $from . '"',
                );
            }
            if ($ref !== null) {
                throw new MalformedSourceEntry(
                    $field . '.ref is not allowed for adapter "' . $from . '"',
                );
            }
        } else {
            if ($path !== null) {
                throw new MalformedSourceEntry(
                    $field . '.path is not allowed for adapter "' . $from . '" (dir only)',
                );
            }
            if (ProviderId::isUrlOnlySource($from)) {
                if ($url === null) {
                    throw new MalformedSourceEntry(
                        $field . '.url is required for adapter "' . $from . '"',
                    );
                }
                if ($package !== null) {
                    throw new MalformedSourceEntry(
                        $field . '.package is not allowed for adapter "' . $from . '" (use url)',
                    );
                }
            } else {
                if ($package === null) {
                    throw new MalformedSourceEntry(
                        $field . '.package is required for adapter "' . $from . '"',
                    );
                }
                if ($url !== null) {
                    throw new MalformedSourceEntry(
                        $field . '.url is not allowed for adapter "' . $from . '" (use package)',
                    );
                }
            }
        }

        if ($ref !== null && $ref !== SourceEntry::SELF_VERSION) {
            self::assertSupportedRef($ref, $field);
        }

        $skills = self::mapSourceSkills($entry['skills'] ?? null, $field);

        $extras = self::collectExtras($entry);

        return new SourceEntry(
            from: $from,
            package: $package,
            url: $url,
            host: $host,
            ref: $ref,
            extras: $extras,
            skills: $skills,
            path: $path,
        );
    }

    /**
     * Read an optional string field that must be non-empty when present.
     * Returns null when the key is absent.
     *
     * @param non-empty-string $field path prefix for error messages, e.g. `skills.json: sources[0]`
     * @param non-empty-string $name field name inside the entry
     *
     * @return non-empty-string|null
     *
     * @throws MalformedSourceEntry
     *
     * @psalm-pure
     */
    private static function optionalNonEmptyString(mixed $value, string $field, string $name): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!\is_string($value) || $value === '') {
            throw new MalformedSourceEntry(
                $field . '.' . $name . ' must be a non-empty string',
            );
        }
        return $value;
    }

    /**
     * Pick adapter-specific extras out of a `sources[]` entry — anything
     * that is not one of the well-known keys (`from`, `package`, `url`,
     * `host`, `ref`, `skills`, `path`). Stored verbatim so adapters can
     * read their own keys (`sha256` on `zip`, custom proxy options on
     * `go`, …) without a mapper-level schema for every adapter.
     *
     * @param array<array-key, mixed> $entry
     *
     * @return array<string, mixed>
     *
     * @psalm-pure
     */
    private static function collectExtras(array $entry): array
    {
        /** @var array<string, mixed> $extras */
        $extras = [];
        /** @var mixed $value */
        foreach ($entry as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            if (
                $key === 'from'
                || $key === 'package'
                || $key === 'url'
                || $key === 'host'
                || $key === 'ref'
                || $key === 'skills'
                || $key === 'path'
            ) {
                continue;
            }
            /** @psalm-suppress MixedAssignment intentional — adapter-specific extras are stored verbatim */
            $extras[$key] = $value;
        }

        return $extras;
    }

    /**
     * Parse the optional `sources[].skills` allowlist. Three states:
     *
     * - absent / `null` → no filter, every skill is synced (default);
     * - non-empty list → only the listed skills are synced;
     * - empty list (`[]`) → the donor is registered but no skills are
     *   pulled from it (useful for staging or temporary opt-out
     *   without deleting the entry).
     *
     * Non-list values, or lists with non-string / empty-string
     * elements, are load-time errors.
     *
     * @return list<non-empty-string>|null
     *
     * @throws MalformedSourceEntry
     *
     * @psalm-pure
     */
    private static function mapSourceSkills(mixed $raw, string $field): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!\is_array($raw) || !\array_is_list($raw)) {
            throw new MalformedSourceEntry(
                $field . '.skills must be a list of skill names',
            );
        }
        /** @var list<non-empty-string> $out */
        $out = [];
        /** @var mixed $name */
        foreach ($raw as $i => $name) {
            if (!\is_string($name) || $name === '') {
                throw new MalformedSourceEntry(\sprintf(
                    '%s.skills[%d] must be a non-empty string',
                    $field,
                    $i,
                ));
            }
            $out[] = $name;
        }
        return $out;
    }

    /**
     * Reject a `ref` that carries version-constraint syntax the
     * {@see RefResolver} cannot satisfy.
     *
     * Adapters treat any `ref` they do not recognise as a constraint as
     * a literal tag / branch / SHA and hand it to the host verbatim.
     * That is the right default for `main` or `v1.2.3`, but it turns a
     * near-miss like `>=1.0` into a bare 404 from the archive endpoint —
     * a wrong-looking network error for what is really a typo in the
     * config. Catching it here names the field and lists what is
     * accepted.
     *
     * @param non-empty-string $ref
     * @param non-empty-string $field
     *
     * @throws MalformedSourceEntry
     *
     * @psalm-pure
     */
    private static function assertSupportedRef(string $ref, string $field): void
    {
        $resolver = new RefResolver();
        if (!$resolver->looksLikeConstraint($ref) || $resolver->isConstraint($ref)) {
            return;
        }

        throw new MalformedSourceEntry(\sprintf(
            '%s.ref "%s" is not a supported version constraint — use a caret (^1.2.3), '
            . 'a tilde (~1.2), an exact version (=1.2.3), '
            . 'or a literal tag / branch / commit (v1.2.3, main)',
            $field,
            $ref,
        ));
    }
}
