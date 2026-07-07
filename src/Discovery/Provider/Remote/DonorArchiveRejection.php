<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Remote;

/**
 * Why {@see DonorArchiveInspector} refused to treat an extracted
 * archive as a skill donor.
 *
 * The inspector classifies; each caller phrases the reason for its
 * own channel ({@see \LLM\Skills\Add\AddRunner} writes to the console,
 * {@see RemoteProvider} accumulates warnings), so the enum carries the
 * *what* and leaves the *wording* to the caller.
 */
enum DonorArchiveRejection
{
    /**
     * `composer.json` exists but could not be read off disk.
     */
    case ComposerJsonUnreadable;

    /**
     * `composer.json` is present but not valid JSON.
     */
    case ComposerJsonInvalidJson;

    /**
     * `composer.json` decoded to something other than an object.
     */
    case ComposerJsonNotObject;

    /**
     * Neither a `composer.json` declaring `extra.skills.source` nor any
     * `SKILL.md` file — the archive is not a donor in any accepted shape.
     */
    case NoDonorShape;

    /**
     * The archive ships `SKILL.md` files but there is no package name to
     * register it under (no usable `composer.json` name AND no hint from
     * the adapter side).
     */
    case NoPackageName;
}
