<?php

declare(strict_types=1);

namespace LLM\Skills\Add;

use Composer\IO\IOInterface;
use Internal\Path;
use LLM\Skills\Config\AddOptions;
use LLM\Skills\Config\Mapper\VendorConfigMapper;
use LLM\Skills\Config\RemoteEntry;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapter;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Remote\Adapter\ParsedAddInput;
use LLM\Skills\Discovery\Provider\Remote\Adapter\RemoteResolveException;
use LLM\Skills\Discovery\Provider\Remote\Adapter\UnknownAdapterException;
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
        private VendorConfigMapper $vendorMapper = new VendorConfigMapper(),
        private RefResolver $refResolver = new RefResolver(),
    ) {}

    public function run(Path $projectRoot, IOInterface $io, AddOptions $options): int
    {
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

        if (!$this->validateArchiveShipsSkillsSource($extractedRoot, $io, $adapter->id(), $parsed)) {
            return Command::FAILURE;
        }

        // Step 6: upsert into skills.json.
        $entry = new RemoteEntry(
            from: $parsed->from,
            package: $parsed->package,
            url: $parsed->url,
            host: $parsed->host,
            ref: $storedRef,
        );

        try {
            $this->writer->upsertRemote($projectRoot, $entry);
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

        return Command::SUCCESS;
    }

    /**
     * Build a {@see RemoteEntry} from the parsed input for the
     * adapter's `resolve()` call. The synthesised entry differs
     * from what will eventually land in `skills.json` only in that
     * its `ref` is the user-typed value (or null) — the storage-level
     * caret derivation runs after `resolve()`.
     *
     * @psalm-pure
     */
    private static function syntheticEntry(ParsedAddInput $parsed): RemoteEntry
    {
        return new RemoteEntry(
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
     * Pick an adapter for the user's input. If `$options->from` is
     * set, look it up directly. Otherwise infer from the input
     * URL's host: scan the registry for the adapter whose
     * {@see HostAdapter::defaultHost()} matches the URL's scheme + host.
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

        $inferred = $this->inferAdapterFromUrl($options->input);
        if ($inferred === null) {
            if (self::looksLikeUrl($options->input)) {
                $io->writeError(\sprintf(
                    '<error>[llm/skills] could not infer adapter from URL host in "%s"; '
                    . 'pass --from=<adapter> (e.g. --from=github)</error>',
                    $options->input,
                ));
            } else {
                $io->writeError(
                    '<error>[llm/skills] --from is required for shorthand input; '
                    . 'pass --from=<adapter> (e.g. --from=github)</error>',
                );
            }
            return null;
        }
        return $inferred;
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
     * Confirm the fetched archive contains a `composer.json` with
     * `extra.skills.source` — refusing to register a donor we cannot
     * actually use.
     */
    private function validateArchiveShipsSkillsSource(
        Path $extractedRoot,
        IOInterface $io,
        string $adapterId,
        ParsedAddInput $parsed,
    ): bool {
        $composerJsonPath = (string) $extractedRoot->join('composer.json');
        if (!\is_file($composerJsonPath)) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] fetched archive for %s:%s does not contain a composer.json — '
                . 'cannot register as a skill donor</error>',
                $adapterId,
                $parsed->package ?? $parsed->url ?? $parsed->from,
            ));
            return false;
        }

        $contents = \file_get_contents($composerJsonPath);
        if ($contents === false) {
            $io->writeError('<error>[llm/skills] failed to read composer.json from fetched archive</error>');
            return false;
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->writeError('<error>[llm/skills] fetched composer.json is not valid JSON: ' . $e->getMessage() . '</error>');
            return false;
        }
        if (!\is_array($decoded)) {
            $io->writeError('<error>[llm/skills] fetched composer.json must be a JSON object</error>');
            return false;
        }

        /** @var mixed $extra */
        $extra = $decoded['extra'] ?? null;
        if (!VendorConfigMapper::declaresSkills($extra)) {
            $io->writeError(
                '<error>[llm/skills] fetched package does not declare extra.skills.source — '
                . 'cannot register as a skill donor</error>',
            );
            return false;
        }

        return true;
    }
}
