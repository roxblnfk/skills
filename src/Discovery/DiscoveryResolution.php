<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery;

use LLM\Skills\Config\VendorConfig;

/**
 * Output of {@see DiscoveryResolver::resolve()} — partition of the
 * `discoverable` list into "merge into donors" and "leave out".
 *
 * @psalm-immutable
 */
final readonly class DiscoveryResolution
{
    /**
     * @param list<VendorConfig> $included donors to merge into the planner's input list
     * @param list<VendorConfig> $excluded donors not picked up by this run; used for the
     *         `[hint]` notice and the `not-declared` rows in `show`'s Skipped section
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public array $included,
        public array $excluded,
    ) {}
}
