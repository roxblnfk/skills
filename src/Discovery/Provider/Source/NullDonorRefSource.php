<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Source;

use Internal\Path;

/**
 * Default {@see DonorRefSource}: always empty.
 *
 * Lets {@see SourceProvider} be wired into entrypoints before any
 * config surface knows how to declare remote donors — the provider
 * stays inactive, contributes nothing, and the runner falls through
 * to `ComposerProvider` exactly as before.
 *
 * @psalm-immutable
 */
final readonly class NullDonorRefSource implements DonorRefSource
{
    /**
     * @return iterable<RemoteDonorRef>
     *
     * @psalm-pure
     */
    #[\Override]
    public function refs(Path $projectRoot): iterable
    {
        return [];
    }

    /**
     * @return list<string>
     *
     * @psalm-pure
     */
    #[\Override]
    public function warnings(): array
    {
        return [];
    }

    /**
     * @psalm-pure
     */
    #[\Override]
    public function hasRefs(Path $projectRoot): bool
    {
        return false;
    }
}
