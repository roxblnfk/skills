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
| `skills:validate [--fetch]` | — | Validate the project's config, shipped skills and what a sync would install, for CI |
| `skills:lint [<paths>...]` | — | Validate skill directories; output and fixes: [authoring.md](authoring.md#validation) |

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

`skills:validate`

- Checks, in order: `composer.json`; `skills.json`, `skills.local.json` and inline
  `extra.skills` as `skills:update` reads them, target and alias paths included;
  `extra.skills.source` and `extra.skills.sources` as a consumer reads them, and every
  subdirectory of the declared source; the donors and skills `skills:update` would install,
  read where they lie; the other skills in the target, such as ones put there by hand.
- Donors: installed packages and `dir` sources always; remote `sources[]` entries and the
  sources installed packages declare only with `--fetch`, which needs the network
  ([config.md → CI](config.md#ci)). Without it an `INFO` line says what was left out.
- `ERROR` for what breaks the sync and the project fixes: two trusted donors shipping one
  skill name, an entry of its own `sources[]` that cannot be read, a skill in one of its
  `dir` sources. Findings in other donors' skills, and sources an installed package declares
  that cannot be read, are `WARN` at most; so are findings in a target skill matching no
  donor while some donor was not fetched, since it may be that donor's copy.
- Each skill directory is checked once: a shipped skill under its source path, a donor's
  skill at its donor, so an installed copy is not checked again.
- Deprecated or shadowed config keys are `WARN`; a malformed config skips the donor and
  target checks, since the target is unknown.
- Never writes, migrates or installs; `--fetch` fills the source cache only.
- Output as for `skills:lint`. Exits `1` when any finding is an `ERROR`, else
  `0`; warnings never fail it.

`skills:lint`

- Reads no configuration and ignores trust: it validates whatever paths it is given,
  installed or not.
- A path is a skill directory, a `SKILL.md`, or a directory searched for skills
  (`vendor/`, `node_modules/`, `.git/` skipped). Without one it searches the current
  directory, and finding no skill there is a warning rather than an error.
- A search passes over hidden skill directories, which llm/skills never installs. Named
  explicitly, a hidden skill is validated but fails on its hidden name.
- Without a path in a consumer project, the search also covers the installed copies in the
  target; name your own skill directories to leave those out.
- Exits `1` when any finding is an `ERROR` and also on an unknown option, else `0`; it
  never exits `2`.

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
It is skipped in a CI job, detected by a `CI` variable set to anything but empty, `0` or
`false`; there an explicit `skills:update` installs skills. Its failures never fail Composer.
Only `post-update-cmd` migrates deprecated config.

## Standalone binary

A `skills` binary (release assets per OS/arch) exposes the same commands as `update`
(default), `show`, `init`, `add`, `validate`, `lint`; there `-d` is short for `--discovery`.
