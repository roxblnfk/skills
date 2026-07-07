<?php

declare(strict_types=1);

namespace LLM\Skills\Add;

use Composer\IO\IOInterface;
use Internal\Path;
use LLM\Skills\Config\AddOptions;
use LLM\Skills\Config\SourceEntry;
use LLM\Skills\Discovery\Provider\ProviderId;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapter;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Remote\Adapter\ParsedAddInput;
use LLM\Skills\Discovery\Provider\Remote\Adapter\RemoteResolveException;
use LLM\Skills\Discovery\Provider\Remote\Adapter\UnknownAdapterException;
use LLM\Skills\Discovery\Provider\Remote\DonorArchiveInspector;
use LLM\Skills\Discovery\Provider\Remote\DonorArchiveRejection;
use LLM\Skills\Discovery\Provider\Remote\RefResolver;
use LLM\Skills\Discovery\Provider\Remote\RemoteFetchException;
use LLM\Skills\Discovery\Provider\Remote\RemoteFetcher;
use Symfony\Component\Console\Command\Command;

/**
 * Shared body of `skills:add` — independent of which entrypoint
 * invoked it.
 *
 * The flow:
 *
 * 1. **Adapter dispatch** — pick the {@see HostAdapter} from
 *    {@see AddOptions::$from} (explicit) or by inferring from the
 *    input shape (full URL ⇒ adapter that matches the host).
 * 2. **Parse** — adapter normalises the user's input into a
 *    {@see ParsedAddInput} (package / host / ref).
 * 3. **Resolve ref** — fetch the adapter's version listing once to
 *    determine the concrete tag / branch / SHA the cascade would
 *    pick (explicit ref → highest stable tag → default branch HEAD).
 *    The result drives both what's stored and what's downloaded.
 * 4. **Store ref policy** — explicit user-typed ref wins
 *    verbatim; auto-cascade onto a stable semver tag stores
 *    `^X.Y.Z`; everything else stores no ref.
 * 5. **Fetch + validate** — download the archive into the cache
 *    and check it ships a `composer.json` with `extra.skills.source`.
 *    Refuse to register a donor we cannot actually use.
 * 6. **Upsert** — write the entry into `skills.json` via
 *    {@see SkillsJsonWriter} (stable-sort + atomic).
 * 7. **Trigger sync** — when {@see AddOptions::$sync} is true (default),
 *    the entrypoint runs `skills:update` so the new skills land
 *    immediately. The runner returns SUCCESS once the manifest is
 *    written; the entrypoint handles the follow-up sync.
 *
 * Errors at any step return {@see Command::FAILURE} with a clear
 * message via the {@see IOInterface}.
 */
final readonly class AddRunner
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private HostAdapterRegistry $registry,
        private RemoteFetcher $fetcher,
        private SkillsJsonWriter $writer = new SkillsJsonWriter(),
        private RefResolver $refResolver = new RefResolver(),
        private DonorArchiveInspector $inspector = new DonorArchiveInspector(),
    ) {}

    /**
     * @param \Closure(SourceEntry, non-empty-string):void|null $onRegistered
     *         invoked exactly once after the entry is upserted into
     *         `skills.json`. Receives the {@see SourceEntry} AND the
     *         donor's actual Composer-package name (read from the
     *         fetched `composer.json`'s `name` field).
     *
     *         Note: the package name is **not** the same as
     *         `$entry->package` — that field stores the adapter's
     *         identifier (e.g. the GitHub `<owner>/<repo>` path), which
     *         can differ from the package's `name`. The donor's
     *         {@see \LLM\Skills\Config\VendorConfig::$packageName} is
     *         what the planner filters on, so the entrypoint needs the
     *         composer.json `name` to scope the post-add sync correctly.
     */
    public function run(
        Path $projectRoot,
        IOInterface $io,
        AddOptions $options,
        ?\Closure $onRegistered = null,
    ): int {
        // Step 1: adapter selection.
        $adapter = $this->selectAdapter($options, $io);
        if ($adapter === null) {
            return Command::INVALID;
        }

        // Step 2: parse the user's input.
        try {
            $parsed = $adapter->parseAddInput(
                $options->input,
                hostOverride: $options->host,
                refOverride: $options->ref,
            );
        } catch (\InvalidArgumentException $e) {
            $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return Command::INVALID;
        }

        // Step 3 + 4: resolve concrete ref AND compute what to store.
        $synthetic = self::syntheticEntry($parsed);
        try {
            $resolved = $adapter->resolve($synthetic);
        } catch (RemoteResolveException $e) {
            $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $storedRef = $this->decideStoredRef($parsed->ref, $resolved->ref);

        // Step 5: fetch + validate the archive.
        try {
            $extractedRoot = $this->fetcher->fetch($resolved);
        } catch (RemoteFetchException $e) {
            $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $donorPackageName = $this->inspectArchive(
            $extractedRoot,
            $io,
            $adapter->id(),
            $parsed,
        );
        if ($donorPackageName === null) {
            return Command::FAILURE;
        }

        // Step 6: upsert into skills.json. The `--skill` allowlist (if
        // any) is forwarded verbatim; the writer's upsert logic merges
        // it with whatever names already lived in a matching entry.
        $entry = new SourceEntry(
            from: $parsed->from,
            package: $parsed->package,
            url: $parsed->url,
            host: $parsed->host,
            ref: $storedRef,
            skills: $options->skills,
        );

        try {
            $this->writer->upsertSource($projectRoot, $entry);
        } catch (\Throwable $e) {
            $io->writeError('<error>[llm/skills] failed to update skills.json: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $io->write(\sprintf(
            '<info>[llm/skills] registered %s:%s%s</info>',
            $entry->from,
            $entry->identifier(),
            $storedRef !== null ? ' @ ' . $storedRef : '',
        ));

        $onRegistered?->__invoke($entry, $donorPackageName);

        return Command::SUCCESS;
    }

    /**
     * Build a {@see SourceEntry} from the parsed input for the
     * adapter's `resolve()` call. The synthesised entry differs
     * from what will eventually land in `skills.json` only in that
     * its `ref` is the user-typed value (or null) — the storage-level
     * caret derivation runs after `resolve()`.
     *
     * @psalm-pure
     */
    private static function syntheticEntry(ParsedAddInput $parsed): SourceEntry
    {
        return new SourceEntry(
            from: $parsed->from,
            package: $parsed->package,
            url: $parsed->url,
            host: $parsed->host,
            ref: $parsed->ref,
        );
    }

    /**
     * Heuristic match for "input is a URL the user typed" — used only
     * to phrase the adapter-inference error message. Catches the
     * `https?://host/...` form that {@see self::inferAdapterFromUrl()}
     * tries to match; anything else is treated as shorthand.
     *
     * @psalm-pure
     */
    private static function looksLikeUrl(string $input): bool
    {
        return \preg_match('~^https?://~i', $input) === 1;
    }

    /**
     * Pick an adapter for the user's input. Resolution order:
     *
     * 1. Explicit `--from=<id>` wins, full stop.
     * 2. Otherwise, if the input is a full URL, try to infer the
     *    adapter from the URL's scheme + host. An unknown host is a
     *    user error — we do NOT silently fall back to GitHub here,
     *    because that would mean a `https://gitlab.com/...` URL gets
     *    sent to the GitHub API.
     * 3. Otherwise (shorthand input, no `--from`), default to
     *    `github`. GitHub is overwhelmingly the most common donor
     *    source; making it the default trims the most-typed flag off
     *    `skills:add` without locking other adapters out.
     */
    private function selectAdapter(AddOptions $options, IOInterface $io): ?HostAdapter
    {
        if ($options->from !== null) {
            try {
                return $this->registry->get($options->from);
            } catch (UnknownAdapterException $e) {
                $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
                return null;
            }
        }

        if (self::looksLikeUrl($options->input)) {
            $inferred = $this->inferAdapterFromUrl($options->input);
            if ($inferred === null) {
                $io->writeError(\sprintf(
                    '<error>[llm/skills] could not infer adapter from URL host in "%s"; '
                    . 'pass --from=<adapter> (e.g. --from=github)</error>',
                    $options->input,
                ));
                return null;
            }
            return $inferred;
        }

        // Shorthand input + no `--from` → default to GitHub.
        try {
            return $this->registry->get(ProviderId::GITHUB);
        } catch (UnknownAdapterException $e) {
            // Should only fire in a misconfigured registry; surface
            // the message so it is at least debuggable.
            $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return null;
        }
    }

    /**
     * Infer the adapter from a URL by matching its scheme+host
     * against each registered adapter's `defaultHost()`. Returns
     * null when the input is not a full URL or no adapter claims
     * the host.
     *
     * @psalm-mutation-free
     */
    private function inferAdapterFromUrl(string $input): ?HostAdapter
    {
        if (\preg_match('~^(https?://[^/]+)/~', $input, $m) !== 1) {
            return null;
        }
        $inputHost = $m[1];
        foreach ($this->registry->ids() as $id) {
            $adapter = $this->registry->get($id);
            if ($adapter->defaultHost() === $inputHost) {
                return $adapter;
            }
        }
        return null;
    }

    /**
     * Decide what to persist in `skills.json` as the entry's `ref`:
     *
     * - User-typed ref → write verbatim.
     * - No user ref + adapter resolved a stable semver → `^X.Y.Z`.
     * - Otherwise → store no ref (sync will re-cascade each time).
     *
     * @param non-empty-string|null $userTyped
     * @param non-empty-string $resolved
     *
     * @return non-empty-string|null
     *
     * @psalm-mutation-free
     */
    private function decideStoredRef(?string $userTyped, string $resolved): ?string
    {
        if ($userTyped !== null) {
            return $userTyped;
        }
        return $this->refResolver->formatCaret($resolved);
    }

    /**
     * Confirm the fetched archive is a usable donor and return the name
     * to scope the follow-up sync on. Delegates the parse-and-classify
     * rules to the shared {@see DonorArchiveInspector} — the same
     * inspector {@see \LLM\Skills\Discovery\Provider\Remote\RemoteProvider}
     * runs during sync, so the two paths agree on what counts as a donor.
     *
     * Both accepted shapes (Composer-shaped and bare skill repo) carry a
     * package name; `skills:add` only needs the name, so it treats them
     * alike. A rejection is reported to the console and yields `null`.
     *
     * @return non-empty-string|null donor package name, or `null` if the archive cannot be used
     */
    private function inspectArchive(
        Path $extractedRoot,
        IOInterface $io,
        string $adapterId,
        ParsedAddInput $parsed,
    ): ?string {
        $inspection = $this->inspector->inspect($extractedRoot, $parsed->package);

        $rejection = $inspection->rejection;
        if ($rejection !== null) {
            $io->writeError('<error>[llm/skills] '
                . $this->describeRejection($rejection, $inspection->detail, $adapterId, $parsed)
                . '</error>');
            return null;
        }

        return $inspection->packageName;
    }

    /**
     * Phrase a {@see DonorArchiveRejection} for the `skills:add`
     * console channel. The inspector owns the *classification*; the
     * wording (and the "fetched"/`--from` framing) is this command's.
     *
     * @psalm-pure
     */
    private function describeRejection(
        DonorArchiveRejection $rejection,
        ?string $detail,
        string $adapterId,
        ParsedAddInput $parsed,
    ): string {
        return match ($rejection) {
            DonorArchiveRejection::ComposerJsonUnreadable =>
                'failed to read composer.json from fetched archive',
            DonorArchiveRejection::ComposerJsonInvalidJson =>
                'fetched composer.json is not valid JSON: ' . ($detail ?? ''),
            DonorArchiveRejection::ComposerJsonNotObject =>
                'fetched composer.json must be a JSON object',
            DonorArchiveRejection::NoDonorShape => \sprintf(
                'fetched archive for %s:%s ships neither a composer.json with extra.skills.source '
                . 'nor any SKILL.md files — cannot register as a skill donor',
                $adapterId,
                $parsed->package ?? $parsed->url ?? $parsed->from,
            ),
            DonorArchiveRejection::NoPackageName => \sprintf(
                'fetched archive ships SKILL.md files but no package name to register it under '
                . '(composer.json missing AND --from=%s did not derive a vendor/repo identifier)',
                $adapterId,
            ),
        };
    }
}
