# Project configuration

Contents: install · `skills.json` keys · `skills.local.json` · target and aliases · git
hygiene · clean installs · monorepos · CI · uninstall · legacy inline config.

## Install

```bash
composer require --dev llm/skills
```

Composer asks to allow the plugin (for non-interactive runs see [CI](#ci)). In an
interactive terminal,
an unconfigured project gets a one-question setup offer on the next `composer install` /
`update`; `composer skills:init` runs the full five-step wizard (target, aliases, trusted
patterns, auto-sync, clean) and then a sync ([commands.md](commands.md#behaviour---help-leaves-out)).
Non-interactive form:

```bash
composer skills:init --target=.agents/skills --alias=.claude/skills --alias=.cursor/skills --no-sync
```

PHP extensions `intl` and `mbstring` are needed for skills with non-ASCII directory names;
without both, such skills are refused.

## `skills.json`

Project root, committed. Strict: an unknown top-level key or malformed JSON is fatal for
every command (exit 1). Add the schema for editor support:

```json
{
    "$schema": "https://raw.githubusercontent.com/roxblnfk/skills/master/resources/skills.schema.json",
    "aliases": [".claude/skills", ".cursor/skills"]
}
```

| Key | Type | Default | Meaning |
|---|---|---|---|
| `target` | string | `.agents/skills` | Directory skills are copied into; must stay inside the project root |
| `aliases` | string[] | `[]` | Links pointing at the target (symlink on POSIX, junction on Windows); none may equal the target |
| `discovery` | bool | `true` | Also take packages that ship `SKILL.md` without declaring `extra.skills.source` |
| `auto-sync` | bool | `true` | Sync after every `composer install` / `update` |
| `clean` | bool | `false` | Every sync wipes and reinstalls (see [Clean installs](#clean-installs)) |
| `path-from-root` | string | unset | The project's location below an outer root, for monorepos |
| `dependencies` | object | `{}` | Per manager: `composer` → `bool` or `{enabled, trusted, trusted-replace}` |
| `vendor-sources` | bool | `true` | Honour external repositories that installed packages declare in `extra.skills.sources` |
| `sources` | object[] | `[]` | The project's own donors — see [sources.md](sources.md) |

Trust keys (`dependencies.composer.trusted`, `trusted-replace`, `vendor-sources`) are
explained in [security.md](security.md). `dependencies.npm` / `dependencies.go` are
accepted and ignored.

Deprecated top-level `trusted`, `trusted-replace`, `local`, `remote` still load and are
migrated into `dependencies` / `sources` by the next write-mode command; mixing a
deprecated key with its replacement is fatal.

## `skills.local.json`

A personal layer next to `skills.json`, same shape, git-ignored. Typical uses: a skill
pack only you want, a local checkout as a `dir` source, a different target.

```bash
composer skills:add acme/my-private-skills --local
```

Merge rules over the shared file:

| Key | Merge |
|---|---|
| `target`, `path-from-root`, `discovery`, `auto-sync`, `clean`, `vendor-sources` | Local value wins |
| `aliases` | Union (local cannot remove a shared alias) |
| `sources` | Union by identity (`from` + `host` + package/path); a local entry with a shared identity replaces it — `"skills": []` disables a shared donor for you |
| `dependencies.<id>.trusted` | Union |
| `dependencies.<id>.enabled`, `.trusted-replace` | Local value wins |

Local `trusted-replace` and `vendor-sources` can re-open trust the team closed — see
[security.md](security.md#personal-overrides) before writing either. Rejected in the
local file: the deprecated keys and `null` values. Every sync and
`skills:show` prints a `[local] skills.local.json: …` line summarising the layer. Nothing
writes merged values back; `init` and migrations touch `skills.json` only.

## Target and aliases

- The target receives copies; a sync is a non-destructive merge: donor files overwrite,
  extra files in the target stay.
- Each alias is a link to the target. An alias path already occupied by a real directory
  or file is never replaced (`[link-failed]`, exit 1) — move its contents into the target
  or elsewhere, delete it, then sync.
- Removing an alias from config leaves the existing link in place; delete it by hand.
- `--alias` on the command line replaces the configured list for that run.
- Junctions cannot cross Windows volumes.

Which directory an agent reads differs per agent and version; check its documentation.
Claude Code reads `.claude/skills`, Cursor `.cursor/skills`; `skills:init` proposes an
alias for each agent directory already present in the project.

Keep hand-written project skills **outside** the target (for example `skills/<name>/`)
and pull them in with `{"from": "dir", "path": "skills"}` in `sources[]`: an unscoped
clean deletes every skill directory in the target, hand-written ones included, and a
donor skill of the same name merges into it.

## Git hygiene

| Path | Commit? |
|---|---|
| `skills.json` | Commit |
| `skills.local.json` | Ignore — it is personal |
| Alias paths (`.claude/skills`, `.cursor/skills`) | Ignore — they are links the sync recreates |
| Target (`.agents/skills`) | Team choice, see below |

The target is a trade-off:

- **Ignored** — every developer gets skills from `composer install`; skills pulled through
  `skills.local.json` never show up in `git status`. Recommended default.
- **Committed** — skill updates arrive as reviewable diffs after `composer update`, the
  strongest audit trail. Then personal skills from `skills.local.json` land in a tracked
  directory and must be kept out of commits by hand.

`scripts/check-project.php` reports a tracked target or alias and a non-ignored
`skills.local.json`, with the `.gitignore` lines to add.

## Clean installs

| Mode | What is deleted before copying |
|---|---|
| `skills:update --clean` (unscoped) | Every directory in the target holding `SKILL.md` — hand-written ones too. Asks in a terminal; refuses when any donor contributed nothing |
| `skills:update <package> --clean`, `--from=… --clean`, post-`add` | Only the skills this run reinstalls |
| `"clean": true` in config | Every sync, unattended; narrows itself instead of refusing; skipped for a one-off `--target` |
| `--no-clean` | Wins over both |

Preview any clean with `--dry-run`.

## Monorepos

When `composer.json` sits below the repository root (e.g. `packages/api`) and the
target or an alias must live at the outer root, set `"path-from-root": "packages/api"`;
paths then resolve against the outer root. The value is verified against the real
location and the sync aborts when it does not match.

## CI

- Pre-allow the plugin (`config.allow-plugins."llm/skills": true`), or `composer install`
  stops on the plugin question.
- Gate the job on `composer skills:validate`: it exits `1` on a malformed config, an
  invalid shipped skill, or a name conflict between donors that would abort the sync, with
  no network and nothing installed ([commands.md](commands.md#behaviour---help-leaves-out)).
- `composer install` installs no skills in CI: auto-sync is skipped where `CI` is set
  ([commands.md](commands.md#auto-sync)). A job that needs them runs
  `composer skills:update`.
- Remote `sources[]` need network and a GitHub/GitLab token in `COMPOSER_AUTH`
  ([sources.md](sources.md#source-entries)); `composer skills:validate --fetch` checks them
  too and exits `1` on an entry of the project's own that cannot be fetched, which a sync
  only reports and exits `0` on ([commands.md](commands.md#exit-codes)).

## Uninstall

`composer remove llm/skills` deletes nothing it synced. Left behind: the target and its
skills, alias links, `skills.json` / `skills.local.json`, and the download cache under
`vendor/llm-skills/`. Delete them by hand if the project no longer wants them.

## Legacy inline config

Older projects keep settings in the root `composer.json` → `extra.skills`. When
`skills.json` exists those keys are ignored with a `[warn]`; the first write-mode run
(`skills:update`, `skills:init`, `composer update`) moves them into `skills.json`. The
donor keys `extra.skills.source` / `sources` are a package's own publishing settings
([publishing.md](publishing.md)) and stay in `composer.json`.
