# Writing skills

Contents: craft guide · anatomy · frontmatter rules · agent-specific keys · body and
references · validation · where to put the skill.

## Craft guide: `writing-for-agents`

Matt Pocock's `writing-for-agents` skill is the reference for writing anything an agent
reads — skills, `AGENTS.md`, `CLAUDE.md`: context pointers, progressive disclosure,
completion criteria, leading words, pruning. Load it before creating, editing or
reviewing a skill. When it is not installed, offer to add it:

```bash
composer skills:add mattpocock/skills --skill=writing-for-agents
```

Source: `https://github.com/mattpocock/skills` (MIT), path
`skills/productivity/writing-for-agents/`. Older releases called it `writing-great-skills`.
Install from this repository, not from repackaged copies. Pin a tag with `@<tag>` if the
team wants updates to be deliberate.

This file covers the mechanical rules; `writing-for-agents` covers the writing.

## Anatomy

```
my-skill/
├── SKILL.md          required: frontmatter + instructions
├── references/       optional: docs loaded on demand
├── scripts/          optional: code the agent runs
└── assets/           optional: templates, data
```

## Frontmatter rules (Agent Skills spec)

`SKILL.md` starts with `---` on the first line, a YAML mapping, and a closing `---`.

| Key | Rule |
|---|---|
| `name` | Required. 1–64 chars, lowercase `a-z`, digits, single hyphens, no leading/trailing hyphen; equals the directory name |
| `description` | Required. 1–1024 chars, says what the skill does and when to use it, third person |
| `license` | Optional string |
| `compatibility` | Optional, 1–500 chars: environment requirements |
| `metadata` | Optional map of string → string; `metadata.internal: true` is read by llm/skills ([publishing.md](publishing.md)) |
| `allowed-tools` | Optional, experimental: space-separated tools pre-approved for the skill |

Any other key fails `skills-ref validate` and claude.ai / Skills API upload, while Claude
Code, Cursor and VS Code ignore unknown keys silently — a typo goes unnoticed there.
Avoid `anthropic` and `claude` in `name` (rejected by the Claude API) and `<` / `>` in
`description`.

## Agent-specific keys

Valid only in the named agents; use them when the skill targets those agents and accept
that spec validators flag them.

| Key | Agent | Effect |
|---|---|---|
| `disable-model-invocation: true` | Claude Code, Cursor, VS Code / Copilot | Only the user can invoke the skill; the description leaves the agent's context |
| `user-invocable: false` | Claude Code, VS Code / Copilot | Hidden from the slash menu; model-only |
| `argument-hint`, `arguments` | Claude Code, VS Code | Slash-command arguments |
| `when_to_use` | Claude Code | Extra trigger text appended to `description` |
| `allowed-tools` / `disallowed-tools`, `model`, `effort`, `context: fork`, `agent`, `hooks`, `paths` | Claude Code | Execution controls |
| `paths` (formerly `globs`) | Cursor | File-pattern activation |

Codex reads only `name` and `description` (extras go in `agents/openai.yaml`).

## Body and references

Mechanical limits; the writing itself is `writing-for-agents` territory.

- `SKILL.md` under 500 lines and about 5000 tokens; push detail into `references/`.
- Link files with relative forward-slash paths, one level deep from `SKILL.md`.
- Frontmatter fits in the first 4 KiB: llm/skills reads `name` and `metadata.internal`
  only from there.
- Scripts: deterministic, documented `--help`, clear exit codes; state the interpreter.

## Validation

```bash
composer skills:lint path/to/my-skill
```

Each finding is one line, `<LEVEL> <file>[:<line>]: <message>`: the level is `ERROR`,
`WARN` or `INFO` padded to five characters, and the message ends in its fix; `--json`
gives the same as objects. Fix every `ERROR`. Each `WARN` is either fixed or a deliberate choice (for example an
agent-specific key) the user is told about. The spec's reference validator is
`skills-ref validate <path>` from `agentskills/agentskills`.

## Where to put the skill

| Audience | Location |
|---|---|
| This project's team | Outside the target as a `dir` source ([config.md](config.md#target-and-aliases)) |
| Only you | Same, registered in `skills.local.json` |
| Users of your package | The package's `extra.skills.source` directory ([publishing.md](publishing.md)) |
| Many projects | Its own repository, installed with `skills:add` ([sources.md](sources.md)) |
