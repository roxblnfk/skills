# Skill sources and the skill lifecycle

Contents: source entries · refs and caching · allowlists · `dir` sources · repository
layouts · vendor-declared sources · precedence · updating · removing · customising an
installed skill.

`skills.json` → `sources[]` pulls skills from a GitHub or GitLab repository or a local
directory — no Composer package needed. A source entry is **implicitly trusted**: adding
one is a trust decision, so audit the repository first ([audit.md](audit.md)).

Prefer `composer skills:add` over hand-editing ([commands.md](commands.md#behaviour---help-leaves-out)):
it resolves the ref, checks that the repository is a donor, writes the entry atomically
and syncs it.

## Source entries

```json
{
    "sources": [
        { "from": "github", "package": "acme/agent-skills", "ref": "^1.2.0" },
        { "from": "github", "package": "mattpocock/skills", "skills": ["writing-for-agents"] },
        { "from": "gitlab", "package": "group/sub/project", "host": "https://gitlab.acme.dev" },
        { "from": "dir", "path": "../shared-skills", "skills": ["deploy"] }
    ]
}
```

| Field | Applies to | Meaning |
|---|---|---|
| `from` | all | `github`, `gitlab` or `dir`. The reserved ids `bitbucket`, `http`, `zip`, `skills.sh`, `composer`, `npm`, `go` load but install nothing; any other value is a config error |
| `package` | github, gitlab | `owner/repo`; GitLab allows subgroups. For `dir`, an optional display-name override |
| `host` | github, gitlab | Base URL for GitHub Enterprise / self-hosted GitLab |
| `ref` | github, gitlab | See below |
| `path` | dir | Relative to the project root or absolute |
| `skills` | all | Allowlist; absent = all skills, `[]` = none |

Remote sources need PHP `zip` or an `unzip` / `7z` binary. Authentication reuses
Composer's `auth.json` / `COMPOSER_AUTH` (`github-oauth`, `gitlab-token`; self-hosted
GitLab also needs `gitlab-domains`). Configure a GitHub token in CI: every sync of an entry
without an exact ref queries the tags API, which is rate-limited for anonymous calls.

## Refs and caching

Accepted: `^1.2.3`, `^1.2`, `^1`, `~1.2.3`, `~1.2`, `=1.2.3`, or a literal tag, branch or
commit SHA. Rejected at load: `1.*`, `>=1.0`, `^1 || ^2`. Without `ref`, every sync picks
the highest stable tag, then the highest prerelease, then the default branch.

Downloads are cached under `vendor/llm-skills/cache/`. When the fetched repository has a
root `composer.json`, a cached ref is reused as is — a moved tag or a branch keeps its old
content until you delete the cache directory. Repositories without `composer.json` are
downloaded on every sync, so a branch or moved tag changes content without notice. There
is no lockfile or checksum either way: pin a tag or SHA for anything you did not write.

## Allowlists

`skills` names match the skill's frontmatter `name` when it has one, otherwise the
directory name; a skill whose `name` differs from its directory is reachable only by its
`name`. A listed name that is absent is reported under `-v`; an allowlist matching nothing
drops the donor. An allowlist also installs a skill its author marked internal
([publishing.md](publishing.md#private-internal-skills)).

## `dir` sources

Read live on every sync — no cache, so edits show up on the next `skills:update`. The
path may point outside the project. Internal skills are installed from `dir` sources. A
missing directory is skipped with a `-v` warning at sync time and refused at `skills:add`.

## Repository layouts

- A repository with `composer.json` carrying a `name` and `extra.skills.source` is read
  like a Composer donor ([publishing.md](publishing.md#declare-the-source)).
- Otherwise every `SKILL.md` is found by **discovery**: in `.agents/skills`,
  `.claude/skills`, `.cursor/skills`, `skills` or `resources/skills`, flat
  (`<root>/<name>/SKILL.md`) or one category deep (`<root>/<category>/<name>/SKILL.md`);
  when none of those yields a skill, a recursive walk up to five levels that skips
  `vendor`, `node_modules`, hidden directories and nested packages.
- A root `SKILL.md` makes the whole repository one skill, installed under its frontmatter
  `name` (lowercase, hyphenated, at most 64 characters), unless skills exist up to two
  levels down — then the root file is an index and is ignored.
- In a fetched repository, copies under hidden agent directories give way to a visible
  skill of the same name.
- GitHub archives honour the repository's `.gitattributes` `export-ignore`.

## Vendor-declared sources

An installed package may advertise external repositories in its own `composer.json` →
`extra.skills.sources`. They ride on the declaring package's trust
([security.md](security.md#who-is-trusted)); authoring side in
[publishing.md](publishing.md#external-skill-repositories).

## Precedence

When one package arrives through several routes, the project's `sources[]` entry wins over
a vendor-declared source, which wins over the installed Composer package.

## Updating

| Donor | How it updates |
|---|---|
| Composer package | `composer update vendor/pkg`; auto-sync copies the new skills |
| Source with `^`/`~` ref | Floats within the constraint on every sync |
| Source pinned to a tag/SHA, or a new major | Edit `ref`, or re-run `composer skills:add owner/repo@vN` |
| `dir` source | Live; run `composer skills:update` |

Re-audit before widening a ref ([audit.md](audit.md#auditing-updates)).

## Removing

There is no remove command, and a sync never deletes a whole skill on its own: removing a
`sources[]` entry or uninstalling a package leaves its skills in the target. Remove the entry or
package, then either delete the skill directories by hand or run
`composer skills:update --clean` ([config.md](config.md#clean-installs)).

## Customising an installed skill

The next sync replaces an installed skill's directory with the donor copy: edits, added
files and links inside it go (`skills:show` shows `MOD` until then). To customise, copy the
skill outside the target under a new name and add it as a `dir` source. A donor skill whose
name matches a hand-written skill in the target replaces it silently — `[conflict]` only
fires between donors — so give hand-written skills distinct names.
