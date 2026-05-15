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

(Optional) auto-sync on every `composer install` / `update`:

```jsonc
{
  "scripts": {
    "post-install-cmd": ["@composer skills:update"],
    "post-update-cmd":  ["@composer skills:update"]
  }
}
```

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

Project-level settings (`extra.skills.target`, `trusted`, `discovery`) are still read from the
**consumer project's** `composer.json`.


## Commands

```
composer skills:update [<package>...] [options]   # alias: skills:u
composer skills:show   [<package>...] [options]   # alias: skills:s
```

`skills:update` copies skills into the target directory. `skills:show` is read-only — it lists
every donor, the per-skill sync status, and what is being skipped and why.

| Option                | Where  | Description                                                                                                                                                 |
|-----------------------|--------|-------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `<package>...`        | both   | Restrict to matching donors. Exact (`acme/foo`) or wildcard (`acme/*`, `*`). Listed packages are treated as **trusted** for this run (see [Trust](#trust)). |
| `--target=PATH`, `-t` | both   | Override `extra.skills.target`.                                                                                                                             |
| `--trust=PATTERN`     | both   | Trust an extra pattern for this run (repeatable).                                                                                                           |
| `--discovery`         | both   | Include packages that ship a `skills/` directory but do not declare `extra.skills`.                                                                         |
| `--dry-run`           | update | Print actions; no files written.                                                                                                                            |

Short flag `-d` for `--discovery` is registered only by the standalone `bin/skills` binary;
inside Composer it is reserved for `--working-dir`.

### Examples

```bash
composer skills:update                      # sync everything that is trusted
composer skills:update acme/skills-basic    # sync one package (implicit trust)
composer skills:update 'acme/*'             # sync an entire vendor namespace
composer skills:update --discovery          # also include packages without extra.skills
composer skills:update --dry-run            # preview, write nothing
composer skills:show                        # inspect: per-skill status, what is skipped
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

All settings live under `extra.skills` in the consumer project's `composer.json`:

```jsonc
{
  "extra": {
    "skills": {
      "target": ".agents/skills",
      "trusted": ["acme/*", "myorg/skills-internal"],
      "trusted-replace": false,
      "discovery": false
    }
  }
}
```

| Key               | Type     | Default          | Description                                                   |
|-------------------|----------|------------------|---------------------------------------------------------------|
| `target`          | string   | `.agents/skills` | Destination directory, relative to the project root.          |
| `trusted`         | string[] | `[]`             | Extra trust patterns (see [Trust](#trust)).                   |
| `trusted-replace` | bool     | `false`          | When `true`, the built-in trust list is ignored.              |
| `discovery`       | bool     | `false`          | When `true`, auto-discovery is on by default (CLI overrides). |

`.agents/skills/` is tool-agnostic so Claude Code, Cursor, Aider, … can read the same
directory. Redirect to `.claude/skills`, `.cursor/skills`, etc. for single-agent projects.

A malformed `extra.skills` in the project is **fatal**. The same block in a *donor* package is
skipped with a `-v` warning — one bad vendor never blocks the rest.


## Trust

AI skills are Markdown instructions executed by an agent. A malicious package could ship a
prompt-injection payload, so the plugin does not copy skills from a donor unless it is
**trusted**.

Effective trust list:

```
builtin ∪ project.extra.skills.trusted ∪ --trust=<pattern>
```

(`trusted-replace: true` drops `builtin` from the union.)

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

### Built-in trusted vendors

Shipped in [`resources/trusted-vendors.txt`](resources/trusted-vendors.txt); extended by PR.


## Auto-discovery

When a package does not declare `extra.skills` but ships a `skills/` directory at its install
root, `llm/skills` can still pick up the skills inside. Opt in one of three ways:

- `--discovery` flag on the command line (for a single run);
- `extra.skills.discovery: true` in the project (always on);
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
