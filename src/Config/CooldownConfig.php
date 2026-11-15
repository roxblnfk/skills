<?php

declare(strict_types=1);

namespace LLM\Skills\Config;

/**
 * Ages are taken from the host's own timestamps, never from commit dates:
 * the pusher sets those and can backdate a commit past the cooldown.
 *
 * @psalm-immutable
 */
final readonly class CooldownConfig
{
    /**
     * @param int<0, 3650> $days `0` turns the cooldown off
     * @param TrustedVendors $exclude lowercase patterns, matched against the lowercased source `package` (`owner/repo`)
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public int $days,
        public TrustedVendors $exclude,
    ) {}

    /**
     * @psalm-mutation-free
     */
    public function cutoffFor(?string $package, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($this->days === 0) {
            return null;
        }

        if ($package !== null && $package !== '' && $this->exclude->trusts(\strtolower($package))) {
            return null;
        }

        return $now->setTimestamp($now->getTimestamp() - $this->days * 86400);
    }
}
