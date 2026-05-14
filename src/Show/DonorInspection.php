<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

use LLM\Skills\Config\VendorConfig;

/**
 * One donor group in the main listing of `skills:show`.
 *
 * Contains the donor's config (so the formatter can render the package
 * name + `source` dir header), the trust attribution (drives the
 * `[via built-in trust]` annotation), and the per-skill inspections.
 *
 * `trustSource` is `null` only in edge cases the runner does not
 * currently emit: every donor that lands in the main listing went
 * through trust resolution. The field is nullable to leave room for
 * future provenance models (e.g. when `--discover` adds donors that
 * bypass the trust check entirely).
 *
 * @psalm-immutable
 */
final readonly class DonorInspection
{
    /**
     * @param VendorConfig $donor package metadata + source dir
     * @param TrustSource|null $trustSource who approved this donor
     * @param list<SkillInspection> $skills per-skill rows (may be empty when source dir is unreadable)
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public VendorConfig $donor,
        public ?TrustSource $trustSource,
        public array $skills,
    ) {}
}
