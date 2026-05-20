<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

/**
 * Identifies which trust list approved a donor package.
 *
 * Used by `skills:show` to optionally annotate an approved donor with
 * `[via built-in trust]` or `[via direct dependency]`, so the user can
 * audit where their trust decisions come from at a glance.
 *
 * Priority order when multiple lists match: project > cli > builtin >
 * direct-dep. Project wins because it's the durable, version-controlled
 * answer to "do we trust this vendor"; the implicit sources (built-in
 * and direct-dep) sit at the bottom because they exist to silently
 * cover the common case, and a more explicit decision should always
 * take credit when it applies.
 */
enum TrustSource: string
{
    case Project = 'project';
    case Cli = 'cli';
    case Builtin = 'builtin';
    case DirectDep = 'direct-dep';
}
