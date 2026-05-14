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
 * `extra.skills`, drop the positional filter, …).
 *
 * {@see SkipReason::NotDeclared} is reserved for the planned `--discover`
 * flag: packages that ship a `skills/` directory but never declared
 * `extra.skills` in their `composer.json`. With `--discover` off they
 * are invisible by design; with `--discover` on and the feature shipped
 * they would land in the main listing instead. The enum case exists
 * today so consumers can match on it without a follow-up breaking
 * change once `--discover` lands.
 */
enum SkipReason: string
{
    case Untrusted = 'untrusted';
    case UntrustedNamed = 'untrusted-named';
    case Malformed = 'malformed';
    case SourceMissing = 'source-missing';
    case FilteredOut = 'filtered-out';
    case NotDeclared = 'not-declared';
}
