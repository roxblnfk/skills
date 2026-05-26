<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider;

use Composer\Composer;
use Composer\IO\NullIO;
use Composer\Util\HttpDownloader;
use Internal\Path;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Discovery\Provider\Remote\Adapter\GithubAdapter;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Remote\Http\ComposerHttpClient;
use LLM\Skills\Discovery\Provider\Remote\HttpArchiveFetcher;
use LLM\Skills\Discovery\Provider\Remote\RemoteProvider;
use LLM\Skills\Discovery\Provider\Remote\SkillsJsonRemoteDonorSource;

/**
 * Constructs the {@see CompositeDonorProvider} for an entrypoint.
 *
 * Centralises the wiring so the five entrypoints
 * ({@see \LLM\Skills\Composer\SkillsPlugin},
 * {@see \LLM\Skills\Composer\Command\Sync},
 * {@see \LLM\Skills\Composer\Command\Show},
 * {@see \LLM\Skills\Console\Command\Sync},
 * {@see \LLM\Skills\Console\Command\Show})
 * stay one-liners and the activation rules for each provider live in
 * one place.
 *
 * The composite that comes out:
 *
 *  1. **`ComposerProvider`** — local, honours the `local.composer`
 *     toggle. Inactive when no Composer instance is supplied or when
 *     the toggle is `false`.
 *  2. **`RemoteProvider`** — wired with a {@see SkillsJsonRemoteDonorSource}
 *     (reads `remote[]` from `skills.json`) and a {@see HttpArchiveFetcher}
 *     (downloads + extracts archives into `vendor/llm-skills/cache/...`).
 *     Inactive when `remote[]` is empty.
 *
 * Remote is wired LAST so the composite's "later-wins" semantic
 * makes explicit remote entries override transitive local discoveries
 * of the same package name.
 *
 * The builder does a **best-effort** read of `skills.json` to pick up
 * the `local` toggles. If that read throws (malformed config), the
 * builder defaults every provider to its built-in default — the
 * {@see \LLM\Skills\Sync\SyncRunner} then re-reads the same file and
 * surfaces the proper {@see \LLM\Skills\Config\Exception\MalformedProjectConfig}
 * error. Trying to handle the error twice would only produce duplicate
 * messages.
 */
final readonly class DonorProviderBuilder
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private ProjectConfigMapper $mapper = new ProjectConfigMapper(),
    ) {}

    /**
     * @param mixed $extra raw value of the root package's `extra` field, used as
     *        the fallback when no `skills.json` is present.
     */
    public function build(Path $projectRoot, ?Composer $composer, mixed $extra): DonorProvider
    {
        $composerEnabled = $this->resolveLocalEnabled($projectRoot, $extra, ProviderId::COMPOSER);

        $local = new ComposerProvider($composer, enabled: $composerEnabled);
        $remote = $this->buildRemoteProvider($composer);

        return new CompositeDonorProvider($local, $remote);
    }

    /**
     * Build the remote provider. Wires the GitHub adapter, a Composer-
     * backed HTTP client (so auth.json credentials apply), and an
     * archive fetcher into the {@see RemoteProvider} skeleton.
     *
     * Returns an inert {@see RemoteProvider} (default
     * {@see \LLM\Skills\Discovery\Provider\Remote\NullRemoteDonorSource},
     * no fetcher) when no Composer instance is available — i.e. the
     * standalone `bin/skills` binary in a directory without
     * `composer.json`. The remote pipeline depends on Composer's
     * {@see HttpDownloader} for `auth.json` plumbing, and there is
     * no offline-friendly path that can resolve a remote `from` or
     * download an archive. The composite then falls back to the
     * runner's `[llm/skills] no donor providers are active — nothing
     * to sync. Run with -v for details.` notice, which is the
     * documented standalone-without-Composer UX (see
     * {@see \LLM\Skills\Sync\SyncRunner}). `remote[]` entries in
     * `skills.json` are intentionally silent-dropped here — they
     * become live again as soon as a `composer.json` (and therefore
     * an HTTP-capable {@see Composer} instance) is present.
     */
    private function buildRemoteProvider(?Composer $composer): RemoteProvider
    {
        if ($composer === null) {
            return new RemoteProvider();
        }

        $httpClient = new ComposerHttpClient(
            new HttpDownloader(new NullIO(), $composer->getConfig()),
        );

        $registry = new HostAdapterRegistry(new GithubAdapter($httpClient));
        $source = new SkillsJsonRemoteDonorSource($registry, $this->mapper);
        $fetcher = new HttpArchiveFetcher(
            $httpClient,
            $this->guessProjectRootForFetcher($composer),
        );

        return new RemoteProvider($source, $fetcher);
    }

    /**
     * Best-effort project root for the {@see HttpArchiveFetcher}'s
     * cache layout. The fetcher needs to know where to write cached
     * archives; in a Composer context that's the vendor-dir's parent.
     */
    private function guessProjectRootForFetcher(Composer $composer): Path
    {
        /** @var mixed $vendorDir */
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        if (\is_string($vendorDir) && $vendorDir !== '') {
            return Path::create(\dirname($vendorDir));
        }
        return Path::create(\getcwd() ?: '.');
    }

    /**
     * Read the `local.<id>` toggle from `skills.json` (or inline
     * `extra.skills.local`), falling back to
     * {@see ProviderId::defaultLocalEnabled()} on any failure.
     *
     * Malformed config is not surfaced here — the runner does that on
     * its own read.
     */
    private function resolveLocalEnabled(Path $projectRoot, mixed $extra, string $providerId): bool
    {
        try {
            $config = $this->mapper->forProject($projectRoot, $extra)->config;
        } catch (\Throwable) {
            return ProviderId::defaultLocalEnabled($providerId);
        }

        return $config->isLocalEnabled($providerId);
    }
}
