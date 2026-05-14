<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

/**
 * Identifies which trust list approved a donor package.
 *
 * Used by `skills:show` to optionally annotate an approved donor with
 * `[via built-in trust]`, so the user can audit where their trust
 * decisions come from at a glance.
 *
 * Priority order when multiple lists match: project > cli > builtin.
 * Project wins because it's the durable, version-controlled answer to
 * "do we trust this vendor"; built-in is the fallback.
 */
enum TrustSource: string
{
    case Project = 'project';
    case Cli = 'cli';
    case Builtin = 'builtin';
}
