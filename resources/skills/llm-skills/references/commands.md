# Commands

Contents: command list · behaviour `--help` leaves out · exit codes · output tags ·
auto-sync · standalone binary.

`composer <command> --help` lists every flag of the installed version; this file covers
what the help text leaves out.

| Command | Alias | Purpose |
|---|---|---|
| `skills:update [<packages>...]` (formerly `skills:sync`) | `skills:u` | Sync skills into the target |
| `skills:show [<packages>...]` | `skills:s` | Read-only report of donors, skills, trust and skips |
| `skills:init` | `skills:i` | Write `skills.json` (wizard or flags), then sync |
| `skills:add <input>` | `skills:a` | Add a `sources[]` entry, then sync it |

Package arguments and `--trust` take `vendor/package` or `vendor/*`
([security.md](security.md#granting-trust)).

## Behaviour `--help` leaves out

`skills:update`

- Naming packages limits the run to them, and also trusts them and enables discovery for
  them — for this run only.
- `--alias` replaces the configured alias list for the run rather than adding to it.
- `--from=ID` limits the run to one provenance: `composer`, `github`, `gitlab`, `dir`.
- Write-mode runs first migrate deprecated config (`[migrate]` lines).
- A skill-name conflict aborts before anything is written.
- Clean modes: [config.md → Clean installs](config.md#clean-installs).

`skills:show`

- Never writes or migrates.
- Status chips per skill: `OK` in sync · `NEW` not synced yet · `MOD` drift (a donor file
  is missing or differs in the target; extra files in the target are not drift) · `!!`
  name conflict. `[discovered]` marks a donor found without `extra.skills.source`.
- Trust annotations: [security.md](security.md#reading-trust-in-skillsshow). Skip
  reasons: [troubleshooting.md](troubleshooting.md#skip-reasons-skillsshow).

`skills:init`

- Writes only non-default values. Proposes as aliases the `.claude/skills`,
  `.cursor/skills`, `.agents/skills` paths whose agent directory exists and whose
  `skills/` does not; proposes `clean: true` when the target holds no skills yet.
- `--force` keeps keys the wizard does not ask about (`sources`, `path-from-root`).
- A file written with `--path` elsewhere than `skills.json` is not picked up later.

`skills:add`

- Input: `owner/repo`, `owner/repo@ref`, a GitHub/GitLab URL (adapter inferred from the
  host), or a path (`./x`, `../x`, absolute) for a `dir` source.
- Without a ref it resolves the highest stable tag, then the highest prerelease, then the
  default branch, and records `^X.Y.Z` for a tag.
- `--skill` adds to an existing entry's allowlist.
- Refuses an entry that would install nothing. A post-add sync that aborts on a name
  conflict restores the config file; other failures keep the entry.
- Entry grammar and refs: [sources.md](sources.md).

## Exit codes

`0` success — including nothing to sync, a declined `--clean` prompt, and a
`sources[]` entry that failed to fetch during `skills:update` (reported as
`source-failed`) · `1` failure: malformed config, name conflict, alias link failure,
refused clean, fetch error in `skills:add` · `2` invalid input: bad pattern or flag, a
package filter matching no approved donor.

## Output tags

`[copy]` / `[would copy]` · `[remove]` · `[link]` / `[would link]` · `[link-failed]` ·
`[skip]` · `[rename]` · `[conflict]` · `[hint]` · `[warn]` (mostly under `-v`) ·
`[failed]` · `[kept]` · `[remove-failed]` · `[local]` · `[deprecated]` · `[migrate]`.
Add `-v` to see per-file warnings such as skipped symlinks and allowlist misses.

## Auto-sync

Runs on `post-install-cmd` / `post-update-cmd` as a plugin event listener, so
`composer --no-scripts` still runs it; `--no-plugins` or `"auto-sync": false` stops it.
Its failures never fail Composer. Only `post-update-cmd` migrates deprecated config.

## Standalone binary

A `skills` binary (release assets per OS/arch) exposes the same commands as `update`
(default), `show`, `init`, `add`; there `-d` is short for `--discovery`.
