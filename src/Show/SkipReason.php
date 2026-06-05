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
}
