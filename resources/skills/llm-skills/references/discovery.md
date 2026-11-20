# Finding skills

Contents: clarify the need · where to search · search terms · judging a candidate ·
installing the pick.

## Clarify the need

Pin down the task the skill should cover, the agent(s) in use, and whether the project
already has something close: `composer skills:show` lists every installed skill with its
description. A framework or library the project already depends on may ship skills —
check its documentation and `vendor/<pkg>` for `SKILL.md` before searching outside.

## Where to search

Ordered by trust signal, strongest first.

| Source | What it offers | Search |
|---|---|---|
| Packages the project depends on | Skills from maintainers you already trust | `vendor/**/SKILL.md`; `composer skills:show --discovery` |
| Curated collections | Human-reviewed skills — `trailofbits/skills-curated` reviews every entry | Browse the repository |
| Vendor-official repositories | `anthropics/skills` (examples, demo-grade), `NVIDIA/skills` (signed, scanned), `mattpocock/skills` | Browse the repository |
| Packagist | Composer packages shipping skills — installable and trusted the usual way | Tags `skills` and `agent-skills`: `https://packagist.org/search/?tags=agent-skills` |
| skills.sh | Largest cross-agent directory, ranked by installs, with per-skill security audits | `npx skills find <query>` or the website |
| GitHub | Everything else | Topic `agent-skills`; code search `filename:SKILL.md <topic>` |
| Aggregators (SkillsMP, awesome-lists) and ClawHub | Breadth | Treat as a search engine; ClawHub had the largest malicious-skill incident (ClawHavoc) |

## Search terms

`SKILL.md <topic>`, `<framework> skills` (`laravel skills`, `symfony skills`),
`agent skills <topic>`, `claude skills <topic>`. Search by the job ("database migration
review"), not by a guessed skill name.

## Judging a candidate

- Publisher: original author or official org, not a repackaged copy.
- Activity: recent commits or releases, answered issues.
- License present.
- Small and readable; no bundled binaries or archives.
- Description is specific and matches what the body does.
- Registry audit verdicts, where shown — one signal among these.
- Stars and install counts last: they are easy to inflate, and mirrors inherit them.

Then run the full audit ([audit.md](audit.md)) on the pick before it is trusted. The
search is done when the user has a short list — each candidate with its provenance and
the reason it fits — and an audit verdict for the one they pick.

## Installing the pick

| Candidate lives in | Install |
|---|---|
| A Composer package | `composer require --dev vendor/pkg` — a direct dependency is trusted; `composer skills:show vendor/pkg` to confirm |
| A transitive Composer package | Add `vendor/pkg` to `dependencies.composer.trusted` ([security.md](security.md)) |
| A GitHub/GitLab repository | `composer skills:add owner/repo@<tag> --skill=<name>` — pinned and allowlisted |
| Only for yourself | Same command with `--local` |

Prefer one allowlisted skill over a whole repository: unrelated skills from the same
repository otherwise arrive with it.
