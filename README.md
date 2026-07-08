<h1 align="center">llm/skills</h1>

<p align="center">Distribute AI Skills as Composer dependencies</p>

<div align="center">

[![Vibe Index](https://img.shields.io/static/v1?label=Vibe+Index&message=0.6&color=24b16e&style=for-the-badge&logo=data%3Aimage%2Fsvg%2Bxml%3Bbase64%2CPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0iI2ZmZiI%2BPHBhdGggZD0iTTkgNCBROSAxMyAxOCAxMyBROSAxMyA5IDIyIFE5IDEzIDAgMTMgUTkgMTMgOSA0IFoiLz48cGF0aCBkPSJNMTkgMSBRMTkgNiAyNCA2IFExOSA2IDE5IDExIFExOSA2IDE0IDYgUTE5IDYgMTkgMSBaIi8%2BPHBhdGggZD0iTTIwIDE0IFEyMCAxOCAyNCAxOCBRMjAgMTggMjAgMjIgUTIwIDE4IDE2IDE4IFEyMCAxOCAyMCAxNCBaIi8%2BPC9zdmc%2B)](https://github.com/roxblnfk/action-vibe-index)
[![Support on Boosty](https://img.shields.io/static/v1?style=for-the-badge&label=&message=Sponsorship&logo=Boosty&logoColor=white&color=%23F15F2C)](https://boosty.to/roxblnfk)

</div>

<br />

A **Composer plugin** that downloads AI Skills from your Composer/vendor packages **and** from
arbitrary Git repositories (GitHub and GitLab, added with `skills:add`), then keeps them synced into
a project-local directory (default `.agents/skills/`).

An *AI Skill* is a directory containing a `SKILL.md` plus any auxiliary files (templates,
examples, fixtures). The directory name is the skill's identity; coding-agent tools read
`SKILL.md` to learn project-specific instructions, conventions, and recipes.

Skills are assembled in the consumer project on demand, or automatically on `composer install` /
`update`. A package doesn't even have to declare anything: skills are
[auto-discovered](#auto-discovery) by their `SKILL.md` files wherever they live.


## Install

```bash
composer require --dev llm/skills
```

[![PHP](https://img.shields.io/packagist/php-v/llm/skills.svg?style=flat-square&logo=php)](https://packagist.org/packages/llm/skills)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/llm/skills.svg?style=flat-square&logo=packagist)](https://packagist.org/packages/llm/skills)
[![License](https://img.shields.io/packagist/l/llm/skills.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/llm/skills.svg?style=flat-square)](https://packagist.org/packages/llm/skills/stats)

Composer will prompt to allow the plugin during install — answer **y**. (For non-interactive
setups, pre-allow with `"config": { "allow-plugins": { "llm/skills": true } }` in
`composer.json`.)

Then bootstrap your project's config — this is the one command to run first:

```bash
composer skills:init
```

An interactive wizard walks you through target dir, aliases, trusted vendors, and auto-sync,
and writes a `skills.json` you commit alongside `composer.json`. See
[Project configuration](#project-configuration) for the full reference. The plugin still works
without it — defaults are sensible — but committing an explicit `skills.json` is what makes
your skill setup reproducible across the team.

Auto-sync after every `composer install` / `update` is **on by default**, so after `init` you
get fresh skills with no further setup. To opt out, set `"auto-sync": false` in `skills.json`;
`composer install --no-scripts` also suppresses the auto-run for a single invocation without
changing the config.

### Global composer installation

Install once and use the `skills:*` commands in any project:

```bash
composer global require llm/skills
```

Then from any project root:

```bash
composer skills:show
composer skills:update
```

Project-level settings (`target`, `dependencies`, `discovery`, …) live in the consumer project's
`skills.json` at the project root. See [Project configuration](#project-configuration) for the
full reference.


## Commands

```
composer skills:init   [options]                  # alias: skills:i
composer skills:update [<package>...] [options]   # alias: skills:u
composer skills:show   [<package>...] [options]   # alias: skills:s
composer skills:add    <input> [options]          # alias: skills:a
```

`skills:update` copies skills into the target directory. `skills:show` is read-only — it lists
every donor, the per-skill sync status, and what is being skipped and why. `skills:init`
bootstraps a [`skills.json`](#project-configuration) at the project root and (when
`composer.json` carries legacy inline project keys) migrates them out. `skills:add` registers
a donor that lives outside Composer (e.g. a GitHub repository) and immediately fetches its
skills — see [Donor sources](#donor-sources).

| Option                | Where  | Description                                                                                                                                                        |
|-----------------------|--------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `<package>...`        | both   | Restrict to matching donors. Exact (`acme/foo`) or wildcard (`acme/*`, `*`). Listed packages are treated as **trusted** for this run (see [Trust](#trust)).        |
| `--target=PATH`, `-t` | both   | Override the configured target directory for this run.                                                                                                             |
| `--alias=PATH`        | update | Extra path mirrored at the target via a junction/symlink (repeatable). Passing `--alias` at all replaces the configured aliases entirely. See [Aliases](#aliases). |
| `--trust=PATTERN`     | both   | Trust an extra pattern for this run (repeatable).                                                                                                                  |
| `--discovery`         | both   | Include packages that ship `SKILL.md` files but do not declare `extra.skills` (see [Auto-discovery](#auto-discovery)).                                              |
| `--from=ID`           | update | Scope the sync to a single provider id (`composer`, `github`, …). See [Donor sources](#donor-sources).                                                              |
| `--dry-run`           | update | Print actions; no files written.                                                                                                                                   |

Short flag `-d` for `--discovery` is registered only by the standalone `bin/skills` binary;
inside Composer it is reserved for `--working-dir`.

### Examples

```bash
composer skills:update                                   # sync everything that is trusted
composer skills:update acme/skills-basic                  # sync one package (implicit trust)
composer skills:update 'acme/*'                           # sync an entire vendor namespace
composer skills:update --discovery                        # also include packages without extra.skills
composer skills:update --alias=.claude/skills             # mirror target via a junction/symlink
composer skills:update --from=github                      # only refresh remote GitHub donors
composer skills:update --dry-run                          # preview, write nothing
composer skills:show                                      # inspect: per-skill status, what is skipped
composer skills:init                                      # create skills.json (migrating inline keys)
composer skills:add acme/skills                           # register a GitHub donor and sync it (github is the default)
composer skills:add acme/skills \
        --skill=code-review --skill=refactor              # narrow a donor to two skills
```

## Shipping skills (vendor side)

A donor package declares a directory whose immediate subdirectories are its skills:

```jsonc
// vendor/acme/skills-pro/composer.json
{
  "extra": {
    "skills": { "source": "resources/skills" }
  }
}
```

```
acme/skills-pro/
├── composer.json
└── resources/skills/
    ├── refactor/
    │   ├── SKILL.md
    │   └── templates/suggestion.md
    └── migrate/
        └── SKILL.md
```

After `skills:update`, the consumer project gets:

```
<project>/.agents/skills/
├── refactor/{SKILL.md, templates/suggestion.md}
└── migrate/SKILL.md
```

- `source` is relative to the package root.
- Each immediate subdirectory of `source` is one skill, copied recursively.
- Loose files at the root of `source` (e.g. `README.md`) are ignored.
- A package without `extra.skills` is not a donor by default — see [Auto-discovery](#auto-discovery).


## Project configuration

Project-level settings live in a dedicated **`skills.json`** at the project root. The file
is the single source of truth for everything the plugin does in your project — what to copy,
where to put it, who to trust, whether to auto-sync.

```jsonc
// <project-root>/skills.json
{
  "$schema": "https://raw.githubusercontent.com/roxblnfk/skills/master/resources/skills.schema.json",
  "target": ".agents/skills",
  "aliases": [".claude/skills", ".cursor/skills"],
  "discovery": false,
  "auto-sync": true,
  "path-from-root": "packages/api",

  "dependencies": {
    "composer": { "trusted": ["acme/*", "myorg/skills-internal"] }
  },
  "sources": [
    { "from": "github", "package": "acme/skills", "ref": "^1.2.0" },
    { "from": "github", "package": "team/skills-pack", "ref": "^2",
      "skills": ["code-review", "refactor"] }
  ]
}
```

| Key               | Type        | Default          | Description                                                                             |
|-------------------|-------------|------------------|-----------------------------------------------------------------------------------------|
| `target`          | string      | `.agents/skills` | Destination directory, relative to the project root.                                    |
| `aliases`         | string[]    | `[]`             | Mirror paths (junction/symlink) pointing at `target`. See [Aliases](#aliases).          |
| `discovery`       | bool        | `false`          | When `true`, auto-discovery is on by default (CLI overrides).                           |
| `auto-sync`       | bool        | `true`           | Run `skills:update` after `composer install` / `update`. Set to `false` to opt out.     |
| `path-from-root`  | string      | _(unset)_        | The project's own location below an intended outer root, e.g. `packages/api`. When set, `target` and aliases resolve against (and stay inside) that verified root instead of the project directory. See [path-from-root](#path-from-root). |
| `dependencies`    | object      | `{}`             | Per-package-manager config: `<id>` → `bool` (walk toggle) or `{ enabled, trusted, trusted-replace }`. Ids: `composer` (walk default `true`), `npm`/`go` (future, default `false`). `trusted` extends the manager's trust list; `trusted-replace` makes it fully replace the built-in and direct-dependency trust. Deprecated aliases `trusted`, `trusted-replace`, `local` fold into this block. See [Trust](#trust) and [Donor sources](#donor-sources). |
| `sources`         | object[]    | `[]`             | Explicit donor source entries. Managed by `skills:add`; documented in [Donor sources](#donor-sources). |

`.agents/skills/` is tool-agnostic so Claude Code, Cursor, Aider, … can read the same
directory. Redirect to `.claude/skills`, `.cursor/skills`, etc. for single-agent projects.

The fastest way to get a valid `skills.json` is `composer skills:init` (see below). Bootstrap
it once and commit it alongside `composer.json`.

> [!NOTE]
> **`remote` was renamed to `sources`.** Existing files keep working — `remote` is still read
> as a deprecated alias. Any write-mode command (`skills:update`, `skills:init`, `skills:add`)
> migrates the key in place and prints a `[migrate]` line; read-only `skills:show` just emits a
> `[deprecated]` notice. Having both `remote` and `sources` in the same file is a fatal config
> error — keep `sources` only.

> [!NOTE]
> **`trusted`, `trusted-replace` and `local` moved into `dependencies`.** Trust is now
> scoped per package manager, so the flat trust surface and the `local` toggle map collapse
> into one block: `dependencies.<id>` takes a bool (the old `local` toggle) or an object with
> `enabled` / `trusted` / `trusted-replace`. The three legacy keys keep working as deprecated
> aliases — flat `trusted`/`trusted-replace` fold into `dependencies.composer`. Write-mode
> commands migrate the file in place with a `[migrate]` line; `skills:show` just emits a
> `[deprecated]` notice. Having `dependencies` alongside any legacy key in the same file is a
> fatal config error — keep `dependencies` only.

### Strict shape

`skills.json` is **strict**:

- Unknown top-level keys fail the run.
- `$schema` is the only metadata key accepted (and silently stripped from the parsed config).
- A nested `config-file` key is rejected — the file is the config, not a pointer to one.

The PHP mapper is the authoritative validator at runtime; the
[`resources/skills.schema.json`](resources/skills.schema.json) document mirrors it for IDE /
editor support. A malformed `skills.json` is **fatal**; a malformed `extra.skills` block in a
*donor* package is skipped with a `-v` warning so one bad vendor never blocks the rest.

### `skills:init` — bootstrap and migrate

```bash
composer skills:init                  # migrate eagerly (same effect as a future skills:update)
composer skills:init --force          # overwrite an existing skills.json
composer skills:init --path=PATH      # non-default location (won't be auto-discovered)
```

`skills:init` is the explicit version of the migration that `skills:update` runs implicitly.
It exists for two cases:

- Pre-`skills:update` setup — bootstrap `skills.json` before the first sync.
- Standalone projects (no `composer.json` at cwd) — write a stub `skills.json` with the
  `$schema` pointer so editors can pick up the schema; nothing else is touched.

Refusal semantics:

- Refuses to overwrite an existing `skills.json` without `--force`.
- Refuses if the inline `extra.skills` block is malformed — fix `composer.json` first, then
  rerun.
- Refuses if `--path` points at an existing non-file (a directory etc.) with a clear error.

`--path=PATH` honours the project-root containment rule. Subsequent commands only
auto-discover `skills.json` at the project root, so a non-default `--path` also emits a
notice telling the user to move the file.

> [!NOTE]
> **Upgrading from inline `extra.skills`?** Early versions of `llm/skills` kept project
> settings under `extra.skills` in `composer.json`. That surface is deprecated. Starting with
> **1.3.0**, the first write-mode run (`skills:update`, `skills:init`, or the
> `post-update-cmd` auto-sync hook) moves the project keys into `skills.json` automatically
> and prints a `[migrate]` line. `skills:show` and `post-install-cmd` stay read-only and just
> emit a one-line notice. Donor-side `extra.skills.source` is never touched.


## Aliases

A single project often needs the same skills directory available to several coding agents at
once — Claude Code at `.claude/skills`, Cursor at `.cursor/skills`, plus an agent-agnostic
`.agents/skills`. Copying the same bytes into N places wastes disk and forces them out of sync.

`aliases` keeps **one** real directory (`target`) and creates additional paths as
OS-level mirrors:

- **POSIX** — symbolic links via `symlink(2)`.
- **Windows** — directory **junctions** via `mklink /J`. Junctions work without
  admin/dev-mode privilege, unlike `SeCreateSymbolicLink`. Cross-volume junctions are
  refused with a non-zero exit; the plugin never silently degrades to a copy.

```jsonc
// <project-root>/skills.json
{
  "target":  ".agents/skills",
  "aliases": [".claude/skills", ".cursor/skills"]
}
```

`skills:update` produces one real `.agents/skills/` plus two link paths pointing at it. Reads
through any path see the same files.

### Behaviour

- **Idempotent.** A second run sees the existing link and treats it as already-correct.
- **Non-destructive.** If the alias path already exists as a real directory, the run fails
  with a non-zero exit and leaves the directory untouched. To convert it, remove the
  directory manually and re-run — the plugin never destroys user content.
- **Stale aliases not pruned.** Removing an entry from `aliases` does not delete the
  junction/symlink on disk. Clean it up manually if needed.
- **CLI override is total.** `--alias=PATH` (repeatable) replaces the configured `aliases`
  for that run — there is no merging.

```bash
composer skills:update --alias=.claude/skills --alias=.cursor/skills
```

### Git

Alias paths are build artefacts and typically belong in `.gitignore`:

```gitignore
.claude/skills
.cursor/skills
```

On Windows, `git status` reads junctions transparently — but committing a junction is rarely
what you want, so the ignore line is the safer default.


## path-from-root

_Sharing a skills directory above the project (monorepos)._

By default `target` (and any `aliases`) must resolve **inside** the project root — the
directory the command runs in, which is where `composer.json` / `skills.json` live. A relative
`target` resolves from there, and `../…` escapes are rejected by a containment guard.

Some repositories keep the Composer project in a **subdirectory** while the coding agent is
launched from the repository root — e.g. a monorepo whose agent (OpenCode, …) reads skills
from a root-level `.agents/skills`:

```
my-monorepo/                  ← agent launched here; skills wanted here
├─ .agents/skills/            ← desired target
└─ packages/api/              ← the Composer project
   ├─ composer.json
   └─ skills.json
```

Running from `packages/api`, a plain `target: .agents/skills` would land in
`packages/api/.agents/skills`, and `../../.agents/skills` would be rejected.

`path-from-root` re-anchors the guard to a **verified ancestor**. You declare where the
project sits relative to the intended outer root:

```jsonc
// my-monorepo/packages/api/skills.json
{
  "path-from-root": "packages/api",
  "target":         ".agents/skills"
}
```

`skills:update` then:

1. climbs that many levels up from the project (`packages/api` → the monorepo root);
2. **verifies** the climb — the project directory must actually end with `packages/api`,
   otherwise the run aborts with `path-from-root … does not match the project location` and
   writes nothing;
3. resolves `target` (and `aliases`) against that root and confines them to it.

Result: skills land in `my-monorepo/.agents/skills`, reached with a plain `target` — no `..`.

- **The guard is widened, not removed.** A `target` or alias that escapes *the re-anchored
  root* is still rejected. `path-from-root` must be a relative path of plain segments (no
  `.` / `..`, not absolute).
- **Portable.** The suffix (`packages/api`) is identical on every machine; only the absolute
  prefix differs, and it is never written into config.
- **Default unchanged.** Omit `path-from-root` and the containment root is the project root,
  exactly as today.


## Trust

AI skills are Markdown instructions executed by an agent. A malicious package could ship a
prompt-injection payload, so the plugin does not copy skills from a donor unless it is
**trusted**.

Effective trust list:

```
builtin ∪ project.trusted ∪ --trust=<pattern> ∪ direct-deps
```

`project.trusted` is the `dependencies.composer.trusted` array from `skills.json` (the
deprecated flat `trusted` key folds into it). `direct-deps` is the set of packages declared
under `require` and `require-dev` in the consumer's root `composer.json`. Setting
`dependencies.composer.trusted-replace: true` drops both implicit sources
(`builtin` and `direct-deps`) from the union, leaving only project trust and `--trust=` —
the explicit-only mode.

| Pattern          | Matches                               |
|------------------|---------------------------------------|
| `vendor/package` | Exact package name.                   |
| `vendor/*`       | Any package in that vendor namespace. |
| `*`              | Every installed package.              |

Bare `vendor` without `/` is rejected as ambiguous.

### Shortcuts

- **Named on the CLI is implicit trust.** `composer skills:update acme/foo` syncs `acme/foo`
  without consulting the trust list. Naming a vendor wildcard (`acme/*`) extends the grant to
  every package matching the pattern.
- **Named is also implicit auto-discovery.** If the named package does not declare
  `extra.skills`, the plugin still scans it for `SKILL.md` files — discovery is enabled for
  that package only.
- **Direct dependencies are implicit trust.** A package the consumer chose to depend on
  (`require` / `require-dev`) does not need a trust pattern: the dependency declaration is
  already a trust decision. Transitive dependencies are still gated by the trust list.
  Setting `dependencies.composer.trusted-replace: true` turns this off for projects that want
  explicit-only trust.

### Built-in trusted vendors

Shipped in [`resources/trusted-composer.txt`](resources/trusted-composer.txt); extended by PR. Other registries (npm, go) will ship their own per-ecosystem files when the corresponding local providers land — see [`spec-remote.md`](spec-remote.md) §8.


## Donor sources

`llm/skills` reads donors from two axes:

- **Local providers** — walk a manifest the project already owns. Today only `composer`; `npm` and `go` are reserved in the vocabulary but ship later.
- **Remote providers** — fetch an explicit ref from a URL (currently GitHub and GitLab; the format is forward-compatible with Bitbucket, npm registry, Go module proxy, private Packagist, `http`/`zip`).
- **Local directory donors** — the `dir` adapter reads a directory already on disk (a shared skills folder next to the repo, a monorepo sibling); no fetch, no cache. See [Local directory donors](#local-directory-donors-dir).

Both axes coexist. When the same package name arrives via both, the **`sources` entry wins** (you typed it; the transitive Composer pickup is treated as stale) and the displaced donor is logged under `-v`.

### `skills:add` — register a remote donor

```bash
composer skills:add acme/skills                               # latest stable, write "^X.Y.Z" — github is the default
composer skills:add acme/skills --ref=v1.2.3                  # pinned tag
composer skills:add 'acme/skills@main'                        # branch HEAD
composer skills:add https://github.com/acme/skills            # full URL; adapter inferred from host
composer skills:add team/skills --from=gitlab                 # GitLab donor (group/project shorthand)
composer skills:add team/skills \
        --host=https://github.corp.example.com                # GitHub Enterprise
composer skills:add team/skills --from=gitlab \
        --host=https://gitlab.corp.example.com                # self-hosted GitLab
composer skills:add acme/skills \
        --skill=code-review --skill=refactor                  # only these two skills
composer skills:add acme/skills --no-sync                     # only edit skills.json
composer skills:add ./skills                                  # local directory donor (dir)
composer skills:add ../shared-skills --skill=deploy           # sibling folder, single skill
```

`--from` defaults to `github` for shorthand input (`owner/repo`). Pass it explicitly only when targeting a different adapter, or override it when the URL host is ambiguous. Full URLs still resolve the adapter from the host — `--from` is only consulted as an override.

The command:

1. parses the input via the resolved adapter (`github` or `gitlab`);
2. resolves the ref — explicit value wins verbatim; without `--ref` the adapter picks the highest stable tag, falling back to the highest prerelease tag, then to the default branch HEAD;
3. downloads the archive into `vendor/llm-skills/cache/...` (gitignored by virtue of vendor);
4. validates that the archive is a donor — either a `composer.json` with `extra.skills.source`, or (for bare skill repos) at least one `SKILL.md` found by [auto-discovery](#auto-discovery);
5. upserts the entry into `skills.json` `sources[]` (stable-sorted by `(from, host, package)`, atomic write — falls back to `unlink + rename` on Windows where `rename()` refuses to overwrite an existing destination);
6. runs a single-entry sync so the new skills land in the target right away — same ergonomics as `composer require`. Suppress with `--no-sync`.

| Option        | Description                                                                                                   |
|---------------|---------------------------------------------------------------------------------------------------------------|
| `<input>`     | Shorthand `owner/repo`, shorthand with `@ref`, a full URL, or a local directory path (`./skills`, `../shared`, an absolute path). |
| `--from=ID`   | Adapter id (`github`, `gitlab`, `dir`, …). Defaults to `github` for shorthand; inferred from the URL host for a full URL; inferred as `dir` when the input opens with a path prefix. |
| `--host=URL`  | Override the adapter's default host (GitHub Enterprise, self-hosted GitLab, private Packagist).               |
| `--ref=REF`   | Pin a tag, branch, SHA, or Composer-style constraint (`^1.2.3`). Without this, the cascade above runs.        |
| `--skill=NAME`| Restrict the donor to a specific skill directory. Repeatable. Names accumulate across consecutive `skills:add` calls. Without the flag, every skill the donor ships is synced. |
| `--no-sync`   | Skip the automatic single-entry sync after writing `skills.json`.                                              |

Stored entries look like:

```jsonc
{
  "sources": [
    { "from": "github", "package": "acme/skills", "ref": "^1.2.0" },
    { "from": "github", "package": "team/internal-skills",
      "host": "https://github.corp.example.com", "ref": "^1",
      "skills": ["code-review", "refactor"] }
  ]
}
```

The composite key is `(from, host, package | url)`: same triplet = upsert in place, different = append. Manual edits are fine — the next `skills:add` normalises the order.

#### Per-entry skill allowlist

A donor often ships more skills than you want in a given project. The optional `skills` field on each `sources[]` entry narrows the donor to a named subset:

- **Absent / omitted** → sync every skill the donor ships (legacy behaviour).
- **Non-empty list of names** → only those skills are copied; the rest are silently skipped.
- **Empty list (`"skills": []`)** → the donor is registered but no skills are pulled from it. Useful for staging a donor before opting into its content or for temporarily disabling a donor without deleting the entry.
- Names that do not exist in the fetched archive emit a `-v` warning (`skill "X" declared in the skill allowlist but not found in the donor`) so typos surface without aborting the sync.

`skills:add --skill=NAME` is the CLI surface: pass `--skill` repeatedly to build the list. The flag is **additive on upsert** — running `skills:add` again on the same entry adds the new names to whatever was already stored. A follow-up `skills:add` without `--skill` does **not** touch the existing allowlist (whether it was a populated list or an explicit empty one). Removing a name or clearing the allowlist entirely is a manual edit of `skills.json`.

### Local directory donors (`dir`)

The `dir` adapter registers a directory already on disk as an explicit donor — a shared skills folder next to the repo, a monorepo sibling, or a working copy you are iterating on. There is no fetch, no cache, and no ref: the directory is read live on every sync, so edits show up on the next `skills:update` with nothing to invalidate.

```jsonc
{
  "sources": [
    { "from": "dir", "path": "./skills" },
    { "from": "dir", "path": "../shared-skills", "package": "myorg/shared", "skills": ["deploy"] }
  ]
}
```

`skills:add` selects the adapter from the input shape — a path opening with `./`, `../`, `/`, `\`, or a Windows drive letter (`X:`) is treated as a directory; `--from=dir` forces it for a bare name:

```bash
composer skills:add ./skills                       # relative to the project root
composer skills:add ../shared-skills --skill=deploy
composer skills:add /srv/team/skills               # absolute path
composer skills:add D:\team\skills --from=dir
```

- **Path resolution.** A relative `path` resolves from the project root (the same anchor `target` uses); absolute paths (including Windows drive letters) are honoured as-is. `..` segments and locations outside the project root are allowed — a `sources[]` entry is an explicit act of trust. The stored `path` is kept as typed (normalised to forward slashes).
- **Package name.** The donor's name is the entry's `package` override if present, else the directory's own `composer.json` `name`, else it is derived from the resolved path as `<parent>/<basename>` lowercased (e.g. `.../testo/skills` → `testo/skills`).
- **Implicit trust.** Like every `sources[]` entry, a `dir` donor is trusted by declaration — you typed the path, so its skills sync without a `trusted` listing.
- `url`, `host`, and `ref` are not applicable and are rejected (there is no host and no version concept); the per-entry `skills` allowlist behaves exactly as for other adapters. A `path` that does not exist at sync time degrades to a `-v` warning and is skipped, but an explicit `skills:add` of a missing directory is refused up front (a missing directory at add time is a typo).

### Authentication

Remote adapters reuse Composer's `auth.json` / `COMPOSER_AUTH` plumbing — no new credential surfaces. A GitHub token configured for `composer require` works as-is for `skills:add`.

**Self-hosted GitLab.** A private GitLab project answers unauthenticated API calls with `404 Project Not Found` (it hides private projects rather than returning `401`), so the fetch fails until Composer has a token *and* knows the host is a GitLab instance. Composer only attaches the token to hosts listed in `gitlab-domains`:

```bash
composer config --global gitlab-domains gitlab.example.com
composer config --global gitlab-token.gitlab.example.com <personal-access-token>   # scope: read_api
```

Public projects (and everything on `gitlab.com`) need no setup.

### Archive safety

Remote archives are downloaded from a user-configurable `host`, so every zip entry name is validated **before** extraction. Absolute paths (`/foo`, `C:/foo`), `..` segments (`../etc/passwd`), backslash-rooted paths (`\\server\share`), and NUL bytes are rejected as a malformed archive; the fetcher emits a per-ref `-v` warning and never writes to disk. The scratch directory used during extraction is cleaned in a `finally` regardless of success.

### `--from=ID` filter on sync

```bash
composer skills:update --from=composer    # only local Composer donors
composer skills:update --from=github      # only remote GitHub donors
```

The id matches `dependencies.{id}` keys and `sources[].from` values. Each donor's provenance is set at the source: `ComposerProvider` tags `composer`; `RemoteProvider` tags the entry's `from`. The filter is a simple equality check on that tag.

### Dependency walk toggles

```jsonc
{ "dependencies": { "composer": false } }    // disable Composer discovery entirely
```

`dependencies.composer` defaults to `true` (transitive Composer packages are scanned for donors). Set it to `false` — either the bool short form above or `{ "composer": { "enabled": false } }` — to stop scanning, useful when the project wants its donors purely from `sources[]`. The per-manager `trusted` / `trusted-replace` fields configure which of those scanned packages are allowed to ship skills (see [Trust](#trust)).

For the full architectural rationale, the version-resolution cascade, the cache layout, and the multi-registry trust model, see [`spec-remote.md`](spec-remote.md).


## Auto-discovery

When a package does not declare `extra.skills` but ships `SKILL.md` files anyway, `llm/skills`
can still pick up the skills inside. Opt in one of three ways:

- `--discovery` flag on the command line (for a single run);
- `"discovery": true` in `skills.json` (always on);
- Name the package as a positional argument (implicit, per-package — see [Shortcuts](#shortcuts)).

### How skills are found

Discovery looks for the *files* (`SKILL.md`), not a single hard-coded folder, so skills are
found wherever a package keeps them. A directory holding a `SKILL.md` **is** a skill; the
scanner never descends into one (a skill cannot contain a nested skill).

1. **Well-known roots first.** Each of these conventional roots is probed, and inside it both
   the flat layout (`<root>/<name>/`) and the one-level catalog layout
   (`<root>/<category>/<name>/`) are accepted:

   ```
   .agents/skills/   .claude/skills/   .cursor/skills/   skills/   resources/skills/
   ```

2. **Recursive fallback.** Only if *none* of those roots yields a skill does the scanner walk
   the rest of the package tree to find `SKILL.md` files in non-conventional locations (e.g.
   `maintenance/skills/<name>/`). The walk is bounded — it caps depth and skips `vendor/`,
   `node_modules/`, `.git/`, hidden directories, and nested packages (any directory with its
   own `composer.json`).

All of these are discovered (no `extra.skills` anywhere):

```
acme/skills-undeclared/        # flat, well-known root
└── skills/
    └── auto-skill/SKILL.md

nested/skills-tree/            # multiple roots + catalog layout
├── .claude/skills/
│   └── hidden-claude/SKILL.md
└── skills/
    └── php/
        └── hidden-catalog/SKILL.md

acme/maintenance/              # recursive fallback (nothing in a well-known root)
└── maintenance/skills/
    └── triage/SKILL.md
```

```bash
composer skills:update --discovery            # picks up every skill above
composer skills:update acme/skills-undeclared # picks up auto-skill only (named ⇒ trust + discovery)
```

The same scan powers `skills:add` for remote repositories that ship bare skills without a
Composer manifest.

Auto-discovered donors still pass through the trust filter unless they were named on the CLI.
A junction or symlink that escapes the package root is silently rejected.


## Sync behaviour

- **Non-destructive merge.** Files inside the target directory that the donor does *not* ship
  are left alone (your local notes survive). Files the donor *does* ship are overwritten — the
  donor is the source of truth.
- **Idempotent.** Running `skills:update` twice produces the same state with no errors.
- **Transactional on conflicts.** If two donors declare a skill with the same directory name,
  sync aborts *before* touching the filesystem; nothing is written. Every offending package is
  listed in the output.
- **Grouped output.** Copied skills are grouped by donor package; trailing `[skip]` and
  `[hint]` blocks summarise what was left out and how to opt in.
