<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

/**
 * Identifies which trust mechanism approved a donor package.
 *
 * Used by `skills:show` to optionally annotate an approved donor with
 * `[declared in skills.json]`, `[via built-in trust]`, or
 * `[via direct dependency]`, so the user can audit where their trust
 * decisions come from at a glance.
 *
 * Priority order when multiple mechanisms apply: declared > project >
 * cli > builtin > direct-dep. A donor the user declared as a
 * `sources[]` entry is trusted by that declaration itself
 * ({@see \LLM\Skills\Config\VendorConfig::$implicitTrust}) — no list
 * is even consulted for it, so no list may take credit. Project wins
 * among the lists because it's the durable, version-controlled answer
 * to "do we trust this vendor"; the implicit sources (built-in and
 * direct-dep) sit at the bottom because they exist to silently cover
 * the common case, and a more explicit decision should always take
 * credit when it applies.
 */
enum TrustSource: string
{
    case Declared = 'declared';
    case Project = 'project';
    case Cli = 'cli';
    case Builtin = 'builtin';
    case DirectDep = 'direct-dep';
}
