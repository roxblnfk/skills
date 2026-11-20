# Troubleshooting

Contents: first steps · a skill did not arrive · skip reasons · messages.

## First steps

1. `composer skills:show` — find the donor and its row in `Skipped:`.
2. `composer skills:update --dry-run -v` — the plan with per-file warnings.
3. `php <this-skill>/scripts/check-project.php` — configuration and git hygiene.

## A skill did not arrive

| Symptom | Cause | Fix |
|---|---|---|
| Package missing from `skills:show` entirely | It ships no `SKILL.md` where discovery looks | Check `vendor/<pkg>` against the layouts in [sources.md](sources.md#repository-layouts) |
| Listed under `Skipped:` | See the reason code below | — |
| Donor listed, one skill missing | Marked internal, refused name, or excluded by an allowlist | Reason code; [allowlist matching](sources.md#allowlists) |
| Present in the target, absent in the agent | Alias missing or not a link | `skills:show` header; [config.md](config.md#target-and-aliases) |
| Stale content | Auto-sync off, `composer install` from an old lock, or a cached remote ref | `composer skills:update`; [caching](sources.md#refs-and-caching) |
| Hand-written skill disappeared or changed | A clean or a same-named donor skill | [config.md](config.md#target-and-aliases) |

## Skip reasons (`skills:show`)

| Code | Meaning | Fix |
|---|---|---|
| `untrusted` | Donor not trusted | Audit, then trust it ([security.md](security.md#granting-trust)) |
| `malformed` | The package's `extra.skills` is invalid | Read the reason; report to the vendor, or fix your `sources[]` entry |
| `source-missing` | Declared source directory does not exist | Vendor bug, or a wrong `dir` path |
| `filtered-out` | Excluded by the package filter of this run | — |
| `not-declared` | Undeclared `SKILL.md` and discovery off | `--discovery`, name the package, or set `"discovery": true` |
| `source-failed` | A `sources[]` entry never became a donor: download, ref or shape failure | `-v` for the cause; check `ref`, network, `auth.json` |
| `skill-refused` | Unsafe directory name, or a root skill with no usable `name` | Rename ([publishing.md](publishing.md#names)) |
| `target-is-source` | The skill's source is the target itself | Point the `dir` source elsewhere |
| `target-is-link` | The skill's directory in the target is a link | Intended for local checkouts; remove the link to get the donor copy |
| `internal` | Marked internal by its author | [publishing.md](publishing.md#private-internal-skills) |

## Messages

| Message | Meaning | Fix |
|---|---|---|
| `[conflict] skill "X" declared by: a, b` + `Sync aborted` | Two donors (or two paths in one package) ship the same name; nothing was written | Allowlist one side via a source entry, drop trust for one, or ask the vendor to rename. One package twice: [publishing.md](publishing.md#installed-copies-in-the-package-repository) |
| `[skip] N untrusted package(s) were not synced` with a hint naming `--trust=*` and `extra.skills.trusted` | Donors outside the trust rules | Use a vendor pattern in `dependencies.composer.trusted` ([security.md](security.md#granting-trust)) |
| `[hint] … ship undeclared skills but discovery is off` naming `extra.skills.discovery` | Discovery disabled | Set `"discovery": true` in `skills.json` or name the package |
| `[link-failed] … a real directory already exists at the alias path` | An alias path holds a real directory | Move its skills out (into a `dir` source), delete it, sync |
| `… is outside the project root` | `target` or alias escapes the root | Use a path inside the project, or set `path-from-root` |
| `path-from-root … does not match the project location` | Wrong monorepo offset | Fix the value |
| `skills.json: unknown top-level key "X"` | Typo or a key from a newer version | Fix the key, or update `llm/skills` |
| `Invalid vendor pattern "*"` | A pattern must name a vendor | `vendor/*` or `vendor/package` |
| `no installed donor package matches: …` (exit 2) | Package filter matched nothing approved | Check the name with `skills:show` |
| `archive contains an unsafe entry path` | Remote archive with path-traversal entries | Treat the repository as hostile; leave it out |
| `[skip] … a link inside the target` | A link in the target is never written through | Expected for linked checkouts |
| `[warn] skipped symlink/junction` (`-v`) | Donor ships links; their content is not copied | Ask the vendor to ship real files |
| `clean narrowed` | `clean: true` limited itself because a donor contributed nothing | Fix the failing donor |
| `[deprecated] …` | Legacy keys in use | Run any write-mode command to migrate |
