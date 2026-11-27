# The "Agent skills" section of a package README

Contents: before writing · placement · template · docs page · done when · proposing it to
another repository.

This file holds the README text that tells a package's users how to get its skills, for
your own package or a pull request to someone else's.

## Before writing

1. The package ships skills. Find the skill directories through `extra.skills.source` in
   its `composer.json`, or through discovery ([sources.md](sources.md#repository-layouts)).
   No skills yet → set up shipping first ([publishing.md](publishing.md)).
2. List every shipped skill by directory name, and read each `description` — the section
   names them and says what they teach. Skip `metadata.internal: true` skills.
3. Classify the package's trust case ([security.md](security.md#who-is-trusted)), in this
   order — it picks the `<TRUST>` slot:
   - **built-in** — it matches `vendor/llm/skills/resources/trusted-composer.txt`;
   - **direct** — users require it themselves (a tool, a framework, a library);
   - **transitive** — it usually arrives through another package (a bridge, a component,
     a driver), so the consumer must trust it.
4. Read the README for its conventions: heading case, line width, language, whether a
   docs directory exists. An existing mention of skills or `llm/skills` is updated in
   place.

## Placement

- A `## Agent skills` section (heading case as the README has it), after installation
  and usage, before contributing and license.
- A docs directory exists → the section in the README plus a [docs page](#docs-page)
  that expands it.

## Template

The version match — skills in the installed version of the package — is the reason to
recommend the plugin, and the copy says it. Fill the slots and drop the lines that do not
apply; keep the wording, adapting heading case, line width and, for a README in another
language, the language.

````markdown
## Agent skills

<Project> comes with <SKILLS> for coding agents such as Claude Code and Codex, so an agent
can <TEACHES>.

Install the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin:

```bash
composer require --dev llm/skills
```

On every `composer install` and `update` the plugin copies the skills of the project's
packages into `.agents/skills/`, so they match the installed version of <Project>. The
first time, it offers to link them into the agent directories the project has, such as
`.claude/skills/` for Claude Code.

<TRUST>

→ [Agent skills](<docs-page>)
````

| Slot | Fill with |
|---|---|
| `<Project>` | The product name as the README writes it: `Trap`, `Testo` |
| `<SKILLS>` | One skill: "the `trap` [Agent Skill](https://agentskills.io)". A prefixed set: "a set of `testo-*` [Agent Skills](https://agentskills.io)". Two or three unrelated names: "the `foo` and `bar` [Agent Skills](https://agentskills.io)" |
| `<TEACHES>` | What the agent does with the skills, as a run of concrete verbs drawn from their descriptions, under 30 words: *start the server, add `trap()` calls, read what the app sent and take the debug code out again* |
| `<TRUST>` | Built-in or direct trust case: delete the line. Transitive: the block below |
| `→` line | Only when the docs page exists; `<docs-page>` is its real path (`docs/agent-skills.md`, `skills/README.md`) |

The transitive `<TRUST>` block:

````markdown
`<vendor/package>` usually arrives through another package, so trust it: add it to
`dependencies.composer.trusted` in `skills.json` next to `composer.json`, then run
`composer skills:update`:

```json
{"dependencies": {"composer": {"trusted": ["<vendor/package>"]}}}
```
````

A README with no docs page appends the manual setup of the docs page (item 3 below),
under `### Set up by hand`.

## Docs page

The docs page repeats the section under `# Agent skills`, without its `→` line, and adds,
in order:

1. After the first paragraph, a table of every shipped skill:

   ```markdown
   | Skill | Teaches the agent to |
   |---|---|
   | `<skill-name>` | <one line from its description> |
   ```

2. For the direct trust case, in place of `<TRUST>`:

   ```markdown
   The plugin trusts the packages the project requires directly, so requiring
   `<vendor/package>` is enough.
   ```

3. At the end, the manual setup:

   ````markdown
   ## Set up by hand

   If you skipped the first-run offer or installed with `--no-interaction`, create
   `skills.json` next to `composer.json` and run `composer skills:update`. A
   `.claude/skills/` that already holds files has to be moved out of the way first.

   ```json
   {"aliases": [".claude/skills"]}
   ```

   `composer skills:show` lists the skills found and where they come from.
   ````

4. Only when the README already lists a non-Composer way, or the user asks for one:

   ````markdown
   ## Without Composer

   [skills](https://github.com/vercel-labs/skills) installs the skills from GitHub:

   ```bash
   npx skills add <owner/repo> -a claude-code -a codex
   ```

   It takes the default branch, which may be ahead of the <Project> version the project has.
   ````

## Done when

The section sits in place, no `<placeholder>` is left, every skill or prefix named matches
the source directory, `<TRUST>` matches the package's trust case, and every link resolves.

## Proposing it to another repository

Opening the pull request publishes text under the user's name: show the diff and confirm
first. Write the pull request in the repository's language and style, and keep its
description to what changes and why: the skills reach users through `composer install` in
the installed version.
