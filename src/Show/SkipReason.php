<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

/**
 * Why a donor (or its candidate skills) did not land in the main listing
 * under `skills:show`.
 *
 * Reasons are mutually exclusive at the *donor* level — a donor lives in
 * exactly one `Skipped:` row, with one reason. The user reads the reason
 * and decides whether to act (trust the donor, fix the malformed
 * `extra.skills`, drop the positional filter, opt in to discovery, …).
 *
 * {@see SkipReason::NotDeclared} is used when {@see \LLM\Skills\Discovery\SkillTreeScanner}
 * found `SKILL.md` files inside a package that does **not** declare
 * `extra.skills`, but the run did not opt in via `--discovery` /
 * `extra.skills.discovery: true`. Listing the donor here makes the
 * candidate names visible alongside the actionable hint at the bottom of
 * the report. Enabling discovery moves the donor into the main listing
 * (or under `untrusted` if the trust filter rejects it).
 */
enum SkipReason: string
{
    case Untrusted = 'untrusted';
    case Malformed = 'malformed';
    case SourceMissing = 'source-missing';
    case FilteredOut = 'filtered-out';
    case NotDeclared = 'not-declared';

    /**
     * A `sources[]` entry that never became a donor at all — the
     * adapter could not resolve it, the archive would not download, or
     * what came back was not a donor. Distinct from
     * {@see self::Malformed}, which needs a donor to exist before its
     * `extra.skills` can be rejected, and from
     * {@see self::SourceMissing}, which is a donor whose declared
     * source directory is absent.
     *
     * Rows carry the entry's `<from>:<identifier>` spelling rather than
     * a Composer name: the failure happened before any `composer.json`
     * could be read.
     */
    case SourceFailed = 'source-failed';
}
