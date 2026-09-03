<?php

declare(strict_types=1);

namespace LLM\Skills\Config\Mapper;

use Internal\Path;
use LLM\Skills\Config\Exception\MalformedSourceEntry;
use LLM\Skills\Config\Exception\MalformedVendorConfig;
use LLM\Skills\Config\SourceEntry;
use LLM\Skills\Config\VendorConfig;
use LLM\Skills\Discovery\Provider\ProviderId;

/**
 * Maps a donor package's `extra` array (as returned by
 * {@see \Composer\Package\PackageInterface::getExtra()}) into typed
 * {@see VendorConfig} rows.
 *
 * `extra.skills.source` is either a single relative directory or a list
 * of them (a monorepo published as one package may ship skills in
 * several non-contiguous locations). Each entry becomes its own
 * {@see VendorConfig} row — the same one-row-per-container shape
 * auto-discovery already produces — so nothing downstream has to know
 * how many directories the donor declared.
 *
 * Two distinct outcomes:
 *
 * - Package has no `extra.skills.source` → it is not a donor. Use
 * {@see self::declaresSkills()} to detect this before calling
 * {@see self::fromExtra()}; non-donors are skipped **silently**. This
 * covers packages that legitimately use `llm/skills` (e.g. set
 * `aliases`, `auto-sync`, or other root-level options in their own
 * `composer.json`) without donating skills of their own.
 * - Package has `extra.skills.source` but the value is broken →
 * {@see self::fromExtra()} throws {@see MalformedVendorConfig}; the
 * caller emits a `-v` warning and moves on. One bad vendor never blocks
 * the rest of the sync.
 */
final readonly class VendorConfigMapper
{
    /**
     * Quick predicate: does the package opt in to being a donor?
     *
     * A package becomes a donor by setting `extra.skills.source`. The
     * mere presence of an `extra.skills` block is not enough — that
     * block may carry only root-level options (`aliases`, `auto-sync`,
     * etc.) that are meaningful when the package is the root project
     * but should be ignored when it is installed as a vendor dependency.
     *
     * @psalm-pure
     */
    public static function declaresSkills(mixed $extra): bool
    {
        if (!\is_array($extra)) {
            return false;
        }

        /** @var mixed $skills */
        $skills = $extra['skills'] ?? null;
        return \is_array($skills) && \array_key_exists('source', $skills);
    }

    /**
     * Quick predicate: does the package declare external skill sources
     * (`extra.skills.sources`, a non-empty list)?
     *
     * Deliberately cheap and shape-only — a malformed list still answers
     * `true` so the ref source runs the full parse and can surface the
     * error instead of silently skipping the donor. Orthogonal to
     * {@see self::declaresSkills()}: a package may declare either key or
     * both, and each feeds its own discovery path (local rows vs
     * fetched refs).
     *
     * @psalm-pure
     */
    public static function declaresSources(mixed $extra): bool
    {
        if (!\is_array($extra)) {
            return false;
        }

        /** @var mixed $skills */
        $skills = $extra['skills'] ?? null;
        if (!\is_array($skills)) {
            return false;
        }

        /** @var mixed $sources */
        $sources = $skills['sources'] ?? null;
        return \is_array($sources) && $sources !== [];
    }

    /**
     * @param non-empty-string $packageName
     * @param Path $packageRoot absolute install path of the package
     * @param mixed $extra raw value of `composer.json` `extra` field
     *
     * @return non-empty-list<VendorConfig> one row per declared source directory
     *
     * @throws MalformedVendorConfig when `extra.skills` is present but invalid
     */
    public function fromExtra(string $packageName, Path $packageRoot, mixed $extra): array
    {
        if (!\is_array($extra)) {
            throw new MalformedVendorConfig($packageName, 'extra must be an object');
        }

        $skills = $extra['skills'] ?? null;
        if (!\is_array($skills)) {
            throw new MalformedVendorConfig($packageName, 'extra.skills must be an object');
        }

        /** @var mixed $source */
        $source = $skills['source'] ?? null;
        $sources = \is_array($source) ? \array_values($source) : [$source];
        if ($sources === []) {
            throw new MalformedVendorConfig(
                $packageName,
                'extra.skills.source must not be an empty list',
            );
        }

        $donors = [];
        $seen = [];
        /** @var mixed $entry */
        foreach ($sources as $entry) {
            if (!\is_string($entry) || $entry === '') {
                throw new MalformedVendorConfig(
                    $packageName,
                    'extra.skills.source must be a non-empty string or a list of non-empty strings',
                );
            }

            if (Path::create($entry)->isAbsolute()) {
                throw new MalformedVendorConfig(
                    $packageName,
                    'extra.skills.source must be a relative path',
                );
            }

            if (!$packageRoot->join($entry)->match($packageRoot->join('*'))) {
                throw new MalformedVendorConfig(
                    $packageName,
                    'extra.skills.source must not escape the package root',
                );
            }

            if (isset($seen[$entry])) {
                throw new MalformedVendorConfig(
                    $packageName,
                    'extra.skills.source must not contain duplicate entries',
                );
            }
            $seen[$entry] = true;

            $donors[] = new VendorConfig(
                packageName: $packageName,
                packageRoot: $packageRoot,
                source: $entry,
            );
        }

        return $donors;
    }

    /**
     * Parse a donor package's `extra.skills.sources` list — external
     * sources the package advertises in addition to (or instead of) its
     * in-package `source` directories. Entries share the `sources[]`
     * vocabulary of `skills.json` with two vendor-side differences:
     *
     * - path-only adapters (`dir`) are rejected — an in-package path is
     *   what `extra.skills.source` is for, and a vendor-controlled
     *   filesystem path outside its own root has no safe meaning;
     * - the {@see SourceEntry::SELF_VERSION} ref alias is allowed (the
     *   ref source later binds it to the package's installed version).
     *
     * The `sources` key is ignored by {@see self::fromExtra()} on
     * purpose: local rows and external refs feed different discovery
     * paths, and a fetched archive's own `sources` must never be
     * honoured (no transitive remote chains).
     *
     * @param non-empty-string $packageName
     * @param mixed $extra raw value of `composer.json` `extra` field
     *
     * @return list<SourceEntry>
     *
     * @throws MalformedVendorConfig when `extra.skills.sources` is present but invalid
     *
     * @psalm-mutation-free
     */
    public function sourceEntriesFromExtra(string $packageName, mixed $extra): array
    {
        if (!\is_array($extra)) {
            return [];
        }

        /** @var mixed $skills */
        $skills = $extra['skills'] ?? null;
        if (!\is_array($skills) || !\array_key_exists('sources', $skills)) {
            return [];
        }

        /** @var mixed $raw */
        $raw = $skills['sources'];

        // Tailored `dir` rejection runs against the raw list so the
        // message names the actual mistake (declaring a path-only
        // adapter at all) rather than whichever shape rule the shared
        // mapper trips over first.
        if (\is_array($raw) && \array_is_list($raw)) {
            /** @var mixed $entry */
            foreach ($raw as $index => $entry) {
                /** @var mixed $from */
                $from = \is_array($entry) ? ($entry['from'] ?? null) : null;
                if (\is_string($from) && ProviderId::isPathOnlySource($from)) {
                    throw new MalformedVendorConfig($packageName, \sprintf(
                        'extra.skills.sources[%d].from "%s" is not allowed in a vendor package '
                        . '(use extra.skills.source for in-package paths)',
                        $index,
                        $from,
                    ));
                }
            }
        }

        try {
            return SourceEntryMapper::mapList($raw, 'extra.skills.sources');
        } catch (MalformedSourceEntry $e) {
            throw new MalformedVendorConfig($packageName, $e->reason);
        }
    }
}
