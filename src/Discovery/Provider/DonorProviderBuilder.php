<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider;

use Composer\Composer;
use Composer\Config;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Util\HttpDownloader;
use Internal\Path;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Discovery\Provider\Remote\Adapter\GithubAdapter;
use LLM\Skills\Discovery\Provider\Remote\Adapter\GitlabAdapter;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Remote\CachePathBuilder;
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
     * Build a {@see CachePathBuilder} rooted at the supplied Config's
     * `vendor-dir` so a custom value (`deps/`, `build/vendor/`, …) is
     * honoured. Without this, the cache would always land under a
     * hard-coded `vendor/` directory that Composer may not even use,
     * leaving cached archives outside the vendor tree's `.gitignore`.
     * For the no-project mode (a default Config built via
     * {@see Factory::createConfig()}) `vendor-dir` is the literal
     * `vendor` default, which is exactly the right answer.
     */
    private static function cacheBuilderFor(Config $config): CachePathBuilder
    {
        /** @var mixed $vendorDir */
        $vendorDir = $config->get('vendor-dir');
        if (!\is_string($vendorDir) || $vendorDir === '') {
            return new CachePathBuilder();
        }
        return new CachePathBuilder(Path::create($vendorDir)->join('llm-skills/cache'));
    }

    /**
     * Build the remote provider. Wires the GitHub adapter, an HTTP
     * client (so auth.json credentials apply), and an archive fetcher
     * into the {@see RemoteProvider} skeleton.
     *
     * Works **with or without** a Composer instance:
     *
     * - With Composer (Composer plugin entrypoints, standalone bin in a
     *   project directory): the project's own {@see Config} provides
     *   `auth.json` + custom `vendor-dir`.
     * - Without Composer (standalone bin outside any project): a
     *   default Config is built via {@see Factory::createConfig()};
     *   the user-wide `~/.composer/auth.json` and the standard
     *   environment variables still apply, the cache lives under
     *   `<cwd>/vendor/llm-skills/cache`, and `skills.json` is read
     *   from `<cwd>`. This matters because the local Composer donor
     *   is just one provider — refusing to wire remote when local is
     *   unavailable would defeat the multi-source design.
     */
    private function buildRemoteProvider(?Composer $composer): RemoteProvider
    {
        $config = $composer?->getConfig() ?? Factory::createConfig(new NullIO());
        $httpClient = new ComposerHttpClient(
            new HttpDownloader(new NullIO(), $config),
        );

        $registry = new HostAdapterRegistry(
            new GithubAdapter($httpClient),
            new GitlabAdapter($httpClient),
        );
        $source = new SkillsJsonRemoteDonorSource($registry, $this->mapper);
        $fetcher = new HttpArchiveFetcher(
            $httpClient,
            $composer !== null
                ? $this->guessProjectRootForFetcher($composer)
                : Path::create(\getcwd() ?: '.'),
            self::cacheBuilderFor($config),
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
