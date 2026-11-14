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

    /**
     * A skill the donor ships that is never installed because its directory
     * name is unsafe or unusable: hidden, shaped like a Windows short name, not
     * portable, or a root skill with no usable name. The donor itself may still
     * install other skills, so the row names one skill, not the whole donor.
     */
    case SkillRefused = 'skill-refused';

    /**
     * A skill whose source directory is the target itself, such as a root
     * skill declared by `{"from": "dir", "path": "./.agents/skills"}`. The
     * sync has nowhere to copy it, so it is never installed.
     */
    case TargetIsSource = 'target-is-source';

    /**
     * A skill whose directory in the target is a symlink or junction, such as
     * `.agents/skills/pdf` pointing at a local checkout. The sync never writes
     * through a link, so the skill is left as it is, whatever it holds.
     */
    case TargetIsLink = 'target-is-link';

    /**
     * A skill whose `SKILL.md` frontmatter sets `metadata.internal`, meant for
     * the donor's own contributors. It is installed only when the donor's
     * allowlist names it or the donor is a `dir` source; the row names one
     * skill, not the whole donor.
     */
    case Internal = 'internal';
}
