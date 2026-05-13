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

`llm/skills` solves one problem: distributing those instruction bundles as ordinary Composer
dependencies and assembling them in the consumer project on every `composer install` /
`composer update`.

---

## Get Started

### Installation

In the project that should *receive* skills:

```bash
composer require --dev llm/skills
```

[![PHP](https://img.shields.io/packagist/php-v/llm/skills.svg?style=flat-square&logo=php)](https://packagist.org/packages/llm/skills)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/llm/skills.svg?style=flat-square&logo=packagist)](https://packagist.org/packages/llm/skills)
[![License](https://img.shields.io/packagist/l/llm/skills.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/llm/skills.svg?style=flat-square)](https://packagist.org/packages/llm/skills/stats)

You will need to allow the plugin to run:

```jsonc
{
  "config": {
    "allow-plugins": {
      "llm/skills": true
    }
  }
}
```

The plugin exposes one CLI command — `composer skills:sync` — and ships **no** event
subscriptions. Wire it into `post-update-cmd` / `post-install-cmd` yourself if you want it to
run automatically:

```jsonc
{
  "scripts": {
    "post-update-cmd": ["@composer skills:sync"],
    "post-install-cmd": ["@composer skills:sync"]
  }
}
```

### Shipping skills from a vendor package

A donor package declares one directory whose immediate subdirectories are its skills:

```jsonc
// vendor/acme/skills-pro/composer.json
{
  "name": "acme/skills-pro",
  "extra": {
    "skills": {
      "source": "resources/skills"
    }
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

`composer skills:sync` then writes:

```
<project>/.agents/skills/
├── refactor/
│   ├── SKILL.md
│   └── templates/suggestion.md
└── migrate/
    └── SKILL.md
```

Notes:

- The `source` directory is relative to the package root.
- Every immediate subdirectory of `source` is one skill, copied recursively.
- Loose files at the root of `source` (`README.md`, etc.) are ignored.
- A package with no `extra.skills` block is not a donor and is invisible to the plugin.

### Configuring the consumer project

All settings live under `extra.skills` in the project's `composer.json`:

```jsonc
{
  "extra": {
    "skills": {
      "target": ".agents/skills",
      "trusted": ["acme/*", "myorg/skills-internal"],
      "trusted-replace": false
    }
  }
}
```

| Key               | Type            | Default          | Meaning                                              |
|-------------------|-----------------|------------------|------------------------------------------------------|
| `target`          | string          | `.agents/skills` | Destination directory, relative to the project root. |
| `trusted`         | list of strings | `[]`             | Extra trust patterns (see *Trust model* below).      |
| `trusted-replace` | boolean         | `false`          | When `true`, the built-in trust list is ignored.     |

#### Picking a destination

The default `.agents/skills/` is tool-agnostic: any coding agent
(Claude Code, Cursor, Aider, …) can be pointed at the same directory, and
your team avoids deciding which tool to commit to. If you only care about
one agent, redirect via `target`:

```jsonc
// Claude Code project
{ "extra": { "skills": { "target": ".claude/skills" } } }
```

```jsonc
// Cursor project
{ "extra": { "skills": { "target": ".cursor/skills" } } }
```

A broken `extra.skills` block in the project is a **fatal** error — the project owns this
file, so we surface mistakes loudly. Broken blocks in *donor* packages, by contrast, are
skipped with a warning (see *Diagnostics*).

---

## Trust model

AI skills are Markdown instructions executed by an agent. A malicious package could ship a
prompt-injection payload, so the plugin will not copy skills from a donor unless that donor is
**trusted**.

The effective trust list is:

```
builtin ∪ project.extra.skills.trusted ∪ --trust=<pattern>
```

or — when `trusted-replace: true` —

```
project.extra.skills.trusted ∪ --trust=<pattern>
```

Pattern syntax:

| Pattern          | Matches                                   |
|------------------|-------------------------------------------|
| `vendor/package` | The exact package name.                   |
| `vendor/*`       | Any package within that vendor namespace. |

Bare `vendor` without `/` is rejected as ambiguous.

### Built-in trusted vendors

Shipped in `resources/trusted-vendors.php`. Maintainers extend it by PR. The current set:

```
doctrine/* · internal/* · laravel/* · llm/* · spiral/* · symfony/* · testo/* · yiisoft/*
```

### Resolution table

| Scenario                                                      | Trusted donor | Untrusted donor             |
|---------------------------------------------------------------|---------------|-----------------------------|
| Auto-discovery (no positional argument)                       | sync          | skip, with one-line notice  |
| Positional argument names the donor, **interactive** terminal | sync          | prompt `[Y/n]`              |
| Positional argument names the donor, **non-interactive** (CI) | sync          | warning, then sync          |
| `--trust=<pattern>` matches the donor                         | sync          | sync (explicit allowance)   |

Rationale: an explicit `composer skills:sync <package>` already counts as intent. The
interactive prompt is a safety net for humans; CI gets a loud warning instead of a wall.

---

## Command synopsis

```
composer skills:sync [<package>...] [options]
```

### Arguments

| Argument         | Description                                                                                 |
|------------------|---------------------------------------------------------------------------------------------|
| `package` (var…) | Restrict sync to matching donors. Exact name (`acme/skills-pro`) or vendor glob (`acme/*`). |

### Options

| Option                | Description                                                                           |
|-----------------------|---------------------------------------------------------------------------------------|
| `--target=PATH`, `-t` | Destination directory, relative to the project root. Overrides `extra.skills.target`. |
| `--trust=PATTERN`     | Trust an additional pattern for this run (repeatable).                                |
| `--dry-run`           | Print what would happen without touching the filesystem.                              |
| `-v` / `-vv` / `-vvv` | Show diagnostics — malformed donor configs, etc.                                      |

### Exit codes

| Code | Meaning                                                                                |
|------|----------------------------------------------------------------------------------------|
| 0    | Sync completed. Notices/warnings may have been printed.                                |
| 1    | Unrecoverable: malformed root config, or a skill-name conflict between trusted donors. |
| 2    | Invalid invocation (e.g. malformed `<package>` or `--trust` pattern).                  |

---

## Sync behaviour

- **Non-destructive merge.** Files inside the target that the donor *does not* ship are left
  alone (your local notes survive). Files the donor *does* ship are overwritten — the donor
  is the source of truth.
- **Idempotent.** Running `skills:sync` twice in a row produces the same state with no errors.
- **Transactional on conflicts.** If two trusted donors declare a skill with the same
  directory name, sync aborts with exit 1 *before* touching the filesystem. Nothing is
  written. The output lists every offending package.

---

## Diagnostics

Donor packages with a malformed `extra.skills` block (missing `source`, wrong types, …) are
**skipped, not fatal**: one bad vendor never blocks the rest. Run with `-v` to see the warning
text and identify which donor needs fixing:

```bash
composer skills:sync -v
```

```text
[warn] Package "acme/skills-broken": extra.skills.source must be a non-empty string
[copy] greeting ← acme/skills-basic
[copy] code-review ← acme/skills-basic
[copy] refactor ← acme/skills-pro
[copy] migrate ← acme/skills-pro
[llm/skills] synced 4 skill(s) into D:\project\.claude\skills
```

---

## Examples

Sync everything that is allowed:

```bash
composer skills:sync
```

Sync only one donor:

```bash
composer skills:sync acme/skills-basic
```

Sync the entire `acme` namespace:

```bash
composer skills:sync 'acme/*'
```

Trust a one-off donor for a single run:

```bash
composer skills:sync --trust=evil/payload
```

Redirect output to a different directory:

```bash
composer skills:sync --target=docs/agent-skills
```

---

## License

BSD-3-Clause — see [LICENSE.md](LICENSE.md).
