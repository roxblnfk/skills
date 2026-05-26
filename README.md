<h1 align="center">llm/skills</h1>

<p align="center">Distribute AI Skills as Composer dependencies</p>

<div align="center">

[![Support on Boosty](https://img.shields.io/static/v1?style=for-the-badge&label=&message=Sponsorship&logo=Boosty&logoColor=white&color=%23F15F2C)](https://boosty.to/roxblnfk)

</div>

<br />

A **Composer plugin** that copies AI Skills shipped inside vendor packages into a project-local
directory (default `.agents/skills/`).

An *AI Skill* is a directory containing a `SKILL.md` plus any auxiliary files (templates,
examples, fixtures). The directory name is the skill's identity; coding-agent tools read
`SKILL.md` to learn project-specific instructions, conventions, and recipes.

`llm/skills` distributes those instruction bundles as ordinary Composer dependencies and
assembles them in the consumer project — on demand or automatically on `composer install` /
`update`.


## Install

```bash
composer require --dev llm/skills
```

[![PHP](https://img.shields.io/packagist/php-v/llm/skills.svg?style=flat-square&logo=php)](https://packagist.org/packages/llm/skills)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/llm/skills.svg?style=flat-square&logo=packagist)](https://packagist.org/packages/llm/skills)
[![License](https://img.shields.io/packagist/l/llm/skills.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/llm/skills.svg?style=flat-square)](https://packagist.org/packages/llm/skills/stats)

Allow the plugin to run:

```jsonc
{ "config": { "allow-plugins": { "llm/skills": true } } }
```

Auto-sync after every `composer install` / `update` is **on by default** — you don't have to
configure anything to get fresh skills after running Composer. To opt out, drop a
`skills.json` at the project root:

```jsonc
// <project-root>/skills.json
{ "auto-sync": false }
```

`composer install --no-scripts` also suppresses the auto-run for a single invocation without
changing the config.

### Global installation

Install once and use the `skills:*` commands in any project:

```bash
composer global require llm/skills
```

Then from any project root:

```bash
composer skills:show
composer skills:update
```

Project-level settings (`target`, `trusted`, `discovery`, …) live in the consumer project's
`skills.json` at the project root. See [Project configuration](#project-configuration) for the
full reference.


## Commands

```
composer skills:update [<package>...] [options]   # alias: skills:u
composer skills:show   [<package>...] [options]   # alias: skills:s
composer skills:init   [options]                  # alias: skills:i
```

`skills:update` copies skills into the target directory. `skills:show` is read-only — it lists
every donor, the per-skill sync status, and what is being skipped and why. `skills:init`
bootstraps a [`skills.json`](#project-configuration) at the project root and (when
`composer.json` carries legacy inline project keys) migrates them out.

| Option                | Where  | Description                                                                                                                                                        |
|-----------------------|--------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `<package>...`        | both   | Restrict to matching donors. Exact (`acme/foo`) or wildcard (`acme/*`, `*`). Listed packages are treated as **trusted** for this run (see [Trust](#trust)).        |
| `--target=PATH`, `-t` | both   | Override the configured target directory for this run.                                                                                                             |
| `--alias=PATH`        | update | Extra path mirrored at the target via a junction/symlink (repeatable). Passing `--alias` at all replaces the configured aliases entirely. See [Aliases](#aliases). |
| `--trust=PATTERN`     | both   | Trust an extra pattern for this run (repeatable).                                                                                                                  |
| `--discovery`         | both   | Include packages that ship a `skills/` directory but do not declare `extra.skills`.                                                                                |
| `--dry-run`           | update | Print actions; no files written.                                                                                                                                   |

Short flag `-d` for `--discovery` is registered only by the standalone `bin/skills` binary;
inside Composer it is reserved for `--working-dir`.

### Examples

```bash
composer skills:update                      # sync everything that is trusted
composer skills:update acme/skills-basic    # sync one package (implicit trust)
composer skills:update 'acme/*'             # sync an entire vendor namespace
composer skills:update --discovery          # also include packages without extra.skills
composer skills:update --alias=.claude/skills   # mirror target via a junction/symlink
composer skills:update --dry-run            # preview, write nothing
composer skills:show                        # inspect: per-skill status, what is skipped
composer skills:init                        # create skills.json (migrating inline keys)
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
  "trusted": ["acme/*", "myorg/skills-internal"],
  "trusted-replace": false,
  "discovery": false,
  "auto-sync": true
}
```

| Key               | Type     | Default          | Description                                                                             |
|-------------------|----------|------------------|-----------------------------------------------------------------------------------------|
| `target`          | string   | `.agents/skills` | Destination directory, relative to the project root.                                    |
| `aliases`         | string[] | `[]`             | Mirror paths (junction/symlink) pointing at `target`. See [Aliases](#aliases).          |
| `trusted`         | string[] | `[]`             | Extra trust patterns (see [Trust](#trust)).                                             |
| `trusted-replace` | bool     | `false`          | When `true`, the built-in trust list and direct-dependency auto-trust are both ignored. |
| `discovery`       | bool     | `false`          | When `true`, auto-discovery is on by default (CLI overrides).                           |
| `auto-sync`       | bool     | `true`           | Run `skills:update` after `composer install` / `update`. Set to `false` to opt out.     |

`.agents/skills/` is tool-agnostic so Claude Code, Cursor, Aider, … can read the same
directory. Redirect to `.claude/skills`, `.cursor/skills`, etc. for single-agent projects.

The fastest way to get a valid `skills.json` is `composer skills:init` (see below). Bootstrap
it once and commit it alongside `composer.json`.

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


## Trust

AI skills are Markdown instructions executed by an agent. A malicious package could ship a
prompt-injection payload, so the plugin does not copy skills from a donor unless it is
**trusted**.

Effective trust list:

```
builtin ∪ project.trusted ∪ --trust=<pattern> ∪ direct-deps
```

`project.trusted` is the `trusted` array from `skills.json`. `direct-deps` is the set of
packages declared under `require` and `require-dev` in the consumer's root `composer.json`.
Setting `trusted-replace: true` drops both implicit sources
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
  `extra.skills`, the plugin still scans its `skills/` directory — discovery is enabled for
  that package only.
- **Direct dependencies are implicit trust.** A package the consumer chose to depend on
  (`require` / `require-dev`) does not need a trust pattern: the dependency declaration is
  already a trust decision. Transitive dependencies are still gated by the trust list.
  Setting `trusted-replace: true` turns this off for projects that want explicit-only trust.

### Built-in trusted vendors

Shipped in [`resources/trusted-vendors.txt`](resources/trusted-vendors.txt); extended by PR.


## Auto-discovery

When a package does not declare `extra.skills` but ships a `skills/` directory at its install
root, `llm/skills` can still pick up the skills inside. Opt in one of three ways:

- `--discovery` flag on the command line (for a single run);
- `"discovery": true` in `skills.json` (always on);
- Name the package as a positional argument (implicit, per-package — see [Shortcuts](#shortcuts)).

```
acme/skills-undeclared/
├── composer.json   # no extra.skills
└── skills/
    └── auto-skill/SKILL.md
```

```bash
composer skills:update --discovery            # picks up auto-skill
composer skills:update acme/skills-undeclared # also picks up auto-skill (named ⇒ trust + discovery)
```

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
