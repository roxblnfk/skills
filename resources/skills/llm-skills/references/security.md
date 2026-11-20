# Security and trust

Contents: threat model · who is trusted · reading trust in `skills:show` · granting
trust · narrowing trust · personal overrides · filesystem guards · what is never checked ·
hardening profiles.

## Threat model

A skill is instructions an agent follows, plus scripts it may run, with the developer's
privileges. A malicious or compromised package can ship prompt injection or a payload
script, and auto-sync installs it on the next `composer install` / `update`
([commands.md](commands.md#auto-sync)). The plugin's defence is **trust**: a donor that is not trusted is never
copied. Trust is decided by package **name**, per package, all-or-nothing; the content of
trusted skills is never inspected.

## Who is trusted

A Composer package is copied when any of these hold:

1. It is a **direct dependency** — listed in the root `require` or `require-dev`.
2. It matches the **built-in list**: `cycle/*`, `doctrine/*`, `internal/*`, `laravel/*`,
   `llm/*`, `moonshine/*`, `phpstan/*`, `spiral/*`, `symfony/*`, `tempest/*`, `temporal/*`,
   `testo/*`, `yiisoft/*`.
3. It matches the project's `dependencies.composer.trusted`.
4. It matches a `--trust` pattern of the current run, or is named as a positional
   argument of the current run.

Separately:

- Every `sources[]` entry (GitHub, GitLab, `dir`) is trusted because the project declared it.
- External repositories a trusted package declares in `extra.skills.sources` inherit that
  package's trust — a trusted vendor can route you to any repository it names.
- `discovery` only widens which packages are considered; it never bypasses trust.

Everything else is skipped with `[skip] N untrusted package(s) were not synced` and listed
as `untrusted` in `skills:show`. There is no prompt.

## Reading trust in `skills:show`

| Annotation | Approved because |
|---|---|
| `[declared in skills.json]` / `[declared in skills.local.json]` | A `sources[]` entry |
| *(none)* | Your `trusted` list or `--trust` |
| `[via built-in trust]` | The built-in list |
| `[via direct dependency]` | Root `require` / `require-dev` |

When several reasons apply, the first row wins. Bundles fetched through a vendor's
`extra.skills.sources` may show no annotation even though they ride on the declaring
package's trust.

## Granting trust

Patterns are `vendor/package` or `vendor/*`, matched literally and case-sensitively.
Every pattern names a vendor: a bare `*` is rejected, so trusting everything is not
possible. The untrusted-skip hint of the plugin suggests `--trust=*` and the legacy
`extra.skills.trusted` key; use a vendor pattern and `dependencies.composer.trusted`.

Team-wide, persistent — `skills.json`:

```json
{ "dependencies": { "composer": { "trusted": ["acme/*", "other/skills-pack"] } } }
```

One run only:

```bash
composer skills:show --trust='acme/*'          # preview what would be approved
composer skills:update --trust='acme/*'
composer skills:update acme/foo                 # named = trusted for this run
```

`composer skills:init --trust='acme/*'` writes the same list. A repository outside
Composer: `composer skills:add owner/repo --skill=<name>` — the source entry is the trust
grant, and the allowlist is the only per-skill trust that exists.

Before granting trust to anything new, audit it ([audit.md](audit.md)) and prefer the
narrowest pattern: a package over a vendor, an allowlisted source over a whole repository.

## Narrowing trust

To exclude one package of a trusted vendor, switch to an explicit list of the packages
you do want:

```json
{
    "dependencies": {
        "composer": { "trusted": ["symfony/console", "acme/*"], "trusted-replace": true }
    }
}
```

`trusted-replace: true` drops **both** the built-in list and direct-dependency trust;
only `trusted`, `--trust` and `sources[]` remain. Other levers:

| Setting | Effect |
|---|---|
| `"discovery": false` | Only packages that declare `extra.skills.source` |
| `"vendor-sources": false` | Ignore repositories vendors point at |
| `"dependencies": {"composer": false}` | Stop walking `vendor/`; only `sources[]` remain |
| `"auto-sync": false` | Skills change only on an explicit `skills:update` |

## Personal overrides

`skills.local.json` can add trusted patterns (union) and sources, and it **overrides**
`trusted-replace` and `vendor-sources` in both directions: a local
`"trusted-replace": false` restores built-in and direct-dependency trust, and a local
`"vendor-sources": true` restores vendor-declared repositories, both of which the team
may have disabled. Tell the user when you write either key locally. Layer mechanics: [config.md](config.md#skillslocaljson).

## Filesystem guards

What the plugin enforces regardless of trust:

- Symlinks and junctions in a donor are never followed or copied (`[warn] skipped
  symlink/junction` under `-v`).
- A link inside the target is never written through (`[skip] … a link inside the target`).
- Hard-linked target files are detached before being overwritten.
- `target` and aliases must resolve inside the project root.
- Remote archives with unsafe entry names (absolute, `..`, drive letters, NUL) are refused
  whole; symlink entries are not extracted.
- Hidden, Windows-reserved and ambiguous skill directory names are refused.
- Two donors shipping the same skill name abort the sync before anything is written.
- Frontmatter is read from the first 4 KiB of `SKILL.md` only.

## What is never checked

- `SKILL.md` content beyond `name`, `description` and `metadata.internal`.
- Scripts, binaries and archives inside a skill.
- Where a package came from: a fork or a custom repository with a trusted name is trusted.
- Integrity of remote sources: no lockfile, no checksum, no size limit
  ([sources.md](sources.md#refs-and-caching) explains when a ref's content can change).
- Changes between versions of a trusted package: updates are copied without a diff.

Cover these with [audit.md](audit.md).

## Hardening profiles

| Profile | Configuration |
|---|---|
| Default | Built-in + direct dependencies trusted, discovery on, auto-sync on |
| Reviewed | Default + target committed, so every skill change is a diff in review |
| Explicit | `trusted-replace: true` with a curated `trusted` list, `"vendor-sources": false`, pinned `ref` on every source |
| Locked | Explicit + `"auto-sync": false` + `"discovery": false`; skills change only through a deliberate, reviewed `skills:update` |
