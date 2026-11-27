---
name: llm-skills
description: Manual for AI agent skills in a project managed by llm/skills — setup, configuration, publishing, trust, validation, audit, discovery and writing of skills.
disable-model-invocation: true
---

# llm-skills

Everything around agent skills in a project that uses the `llm/skills` Composer plugin:
installing and configuring it, shipping skills from a package, deciding what to trust,
validating and auditing skills, finding new ones, and writing good ones.

`<this-skill>` below is the directory holding this file, typically
`.agents/skills/llm-skills`.

## The model in one screen

- A **skill** is a directory holding `SKILL.md` (YAML frontmatter + Markdown instructions)
  plus any files it references.
- A **donor** is anything that ships skills: an installed Composer package, or an entry
  in `skills.json` → `sources[]` (a GitHub/GitLab repository or a local `dir`).
- On every `composer install` / `composer update` (**auto-sync**) and on
  `composer skills:update`, the plugin collects skills from every **trusted** donor and
  copies them into the **target** (default `.agents/skills/`). **Aliases** such as
  `.claude/skills` and `.cursor/skills` are links to the target, so each agent sees the
  same set.
- **Trust** decides which donors are copied at all. It goes by package name; skill content
  is not reviewed, and a trusted donor's new release lands on the next `composer update`.
- Configuration lives in `skills.json` (committed, team-wide) with an optional
  `skills.local.json` layered on top (git-ignored, personal).

`composer skills:show` is the first move for any question about the current state: it
prints the target, aliases, every donor with its trust reason, each skill's sync status,
and every skipped donor with the reason. `composer skills:<command> --help` is
authoritative for the flags of the installed version.

## Where to go

Read only the file for the branch at hand.

| Task | Read |
|---|---|
| Install the plugin, write `skills.json`, personal `skills.local.json`, target/aliases, `.gitignore`, clean installs, CI, uninstall | [references/config.md](references/config.md) |
| What a command does beyond `--help`; exit codes; output tags; auto-sync | [references/commands.md](references/commands.md) |
| Pull skills from a GitHub/GitLab repository or a local directory; update, remove or customise an installed skill | [references/sources.md](references/sources.md) |
| Ship skills from your own Composer package; keep dev-only skills private | [references/publishing.md](references/publishing.md) |
| README "Agent skills" section for a package that ships skills — yours or another's | [references/readme.md](references/readme.md) |
| Why a donor is or is not trusted; trust a vendor, package or source; lock trust down | [references/security.md](references/security.md) |
| Review a third-party skill before trusting it; security scanners | [references/audit.md](references/audit.md) |
| Find a skill for a need | [references/discovery.md](references/discovery.md) |
| Write, edit or improve a skill; frontmatter rules | [references/authoring.md](references/authoring.md) |
| A `[skip]`, `[conflict]`, `[link-failed]` or other message to explain; a skill that did not arrive | [references/troubleshooting.md](references/troubleshooting.md) |

## Checks

Deterministic checks, run from the project root; each accepts `--help` and `--json`.

| Command | Checks |
|---|---|
| `composer skills:validate [--fetch]` | The project, for CI: its config, the skills it ships, and the donors and skills a sync would install |
| `composer skills:lint [PATH...]` | Skill structure and frontmatter against the Agent Skills spec and the plugin's own rules |
| `php <this-skill>/scripts/check-project.php [--root=DIR]` | `skills.json` / `skills.local.json`, git hygiene of target and aliases, a package's skills source layout |
| `php <this-skill>/scripts/scan-skill.php PATH...` | Security pre-screen of third-party skills: hidden Unicode, pipe-to-shell, secret reads, injection phrasing, binaries |

Run `check-project.php` before changing configuration and `skills:validate` after; run
`skills:lint` on every skill you write or edit; run `scan-skill.php` on every
skill from a donor you are about to trust. A finding's message names its fix; report every
`ERROR` to the user before calling the work done.
