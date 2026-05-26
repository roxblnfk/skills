<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider;

use Composer\Composer;
use Internal\Path;
use LLM\Skills\Discovery\DonorDiscovery;
use LLM\Skills\Discovery\DonorDiscoveryResult;

/**
 * Composer/Packagist {@see DonorProvider}.
 *
 * Wraps the existing {@see DonorDiscovery} that walks
 * `Composer::getRepositoryManager()->getLocalRepository()->getPackages()`
 * to enumerate donors. When no `Composer` instance is supplied (the
 * standalone `bin/skills` ran outside any Composer project) the
 * provider reports `isActive() === false` and contributes nothing —
 * the runner falls through to the `no donor providers are active`
 * notice.
 *
 * Bootstrap concerns (how to obtain a `Composer` instance, what to
 * do when `Factory::create()` throws) belong to the entrypoints
 * ({@see \LLM\Skills\Composer\Command\Sync},
 * {@see \LLM\Skills\Console\Command\Sync}); this provider just
 * accepts whatever they pass in.
 */
final readonly class ComposerProvider implements DonorProvider
{
    /**
     * @param bool $enabled honours the `local.composer` toggle from
     *         `skills.json` (spec §3.2). When `false`, the provider
     *         reports {@see self::isActive()} `=== false` even with a
     *         live Composer instance — the user explicitly turned this
     *         ecosystem off. Default `true` preserves the pre-`local`
     *         behaviour for callers that have not yet been migrated.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        private ?Composer $composer = null,
        private bool $enabled = true,
        private DonorDiscovery $discovery = new DonorDiscovery(),
    ) {}

    /**
     * @psalm-suppress MissingPureAnnotation
     *         the inferred-pure body is incidental; the interface contract is impure
     */
    #[\Override]
    public function isActive(Path $projectRoot): bool
    {
        return $this->enabled && $this->composer !== null;
    }

    #[\Override]
    public function discover(Path $projectRoot): DonorDiscoveryResult
    {
        if (!$this->enabled || $this->composer === null) {
            return new DonorDiscoveryResult(donors: [], warnings: []);
        }

        return $this->discovery->discover($this->composer);
    }

    #[\Override]
    public function directDependencies(Path $projectRoot): array
    {
        if (!$this->enabled || $this->composer === null) {
            return [];
        }

        $root = $this->composer->getPackage();
        $names = [];
        foreach ([...$root->getRequires(), ...$root->getDevRequires()] as $name => $_link) {
            if ($name === '' || !\str_contains($name, '/')) {
                // Platform requirements like `php` or `ext-json` and
                // metapackage placeholders never carry skills.
                continue;
            }
            /** @var non-empty-string $name */
            $names[] = $name;
        }

        return $names;
    }
}
