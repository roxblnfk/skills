# Publishing skills from a Composer package

Contents: declare the source · layout rules · names · private (internal) skills ·
dist archive · installed copies in the package repo · external skill repositories ·
release checklist.

## Declare the source

```json
{
    "name": "acme/orm",
    "extra": {
        "skills": { "source": "resources/skills" }
    }
}
```

`source` is a relative path or a list of them (`["packages/a/skills", "packages/b/skills"]`),
inside the package. Every **immediate subdirectory** of a source is one skill, copied
recursively; loose files at the source root are ignored. Only `source` and `sources` are
read from a dependency's `extra.skills` — project settings there have no effect.

Undeclared packages still donate through discovery
([sources.md](sources.md#repository-layouts)), but a declaration is explicit, survives a
consumer's `"discovery": false`, and stops the scan from picking up anything else in the
repository.

A consumer installs your skills only when it trusts your package: as a direct
dependency, through its `trusted` list, or through the built-in list
([security.md](security.md)). The README tells users how to install the skills and what
to trust: [readme.md](readme.md) has the section to add.

## Layout rules

- Each subdirectory of a declared source is installed even without `SKILL.md` (hidden and
  refused names excepted) — keep the source directory free of non-skill folders.
- Reference files from `SKILL.md` by relative paths; the whole directory travels.
- Symlinks inside a skill are never copied; ship real files.
- Skills are installed into a shared target: a name another package also ships aborts the
  consumer's whole sync with `[conflict]`. Prefix generic names with your project
  (`acme-orm-migrations`, not `migrations`).

## Names

The install directory is always the skill's directory name. The plugin refuses
directories that are hidden (`.foo`), Windows device names (`con`, `nul`, `com1`…),
Windows short-name lookalikes (`SKILLS~1`), names ending in a dot or space, names with
`/ \ : < > " | ? *` or control characters, and non-ASCII names on PHP without `intl` and
`mbstring`. Names are compared case-insensitively and after Unicode normalisation.

Follow the Agent Skills spec anyway — lowercase `a-z0-9` and single hyphens, at most 64
characters, frontmatter `name` equal to the directory name — because agents enforce it
and [allowlists](sources.md#allowlists) match `name`. [authoring.md](authoring.md) has the
full rule set.

## Private (internal) skills

Skills for the package's own maintainers — release, internal conventions, test fixtures —
should not reach consumers. Two complementary tools:

1. **Mark them internal** in frontmatter. Consumers skip them (shown as `internal` in
   `skills:show`) unless their allowlist names the skill explicitly:

   ```yaml
   ---
   name: acme-release
   description: Cuts an acme/orm release.
   metadata:
     internal: true
   ---
   ```

   Only `metadata.internal` counts, with value `true` or `1`, written with a space after
   the colon and within the first 4 KiB of `SKILL.md`; a top-level `internal: true` is
   ignored.

2. **Keep them out of the dist archive** with `.gitattributes`, so they are not even
   downloaded:

   ```gitattributes
   /resources/skills-dev export-ignore
   ```

   Composer dist installs and GitHub zip downloads honour it; `--prefer-source` installs
   and path repositories still see everything, so combine it with (1).

To use your internal skills in your own checkout, add the package itself as a `dir`
source in its `skills.json` — internal skills are installed from `dir` sources:

```json
{ "sources": [ { "from": "dir", "path": "resources/skills-dev" } ] }
```

## Dist archive

Check what consumers download: `composer archive --format=zip --dir=/tmp/dist` and list
the archive, or `git archive HEAD | tar -t`. A package whose `.gitattributes` excludes
everything by default (`* export-ignore`) must re-include the skills directory.

## Installed copies in the package repository

If the package repository commits its own synced skills (`.agents/skills`,
`.claude/skills`, `.cursor/skills`) and has **no** `extra.skills.source`, discovery reads
those copies too: a skill present both in `resources/skills/foo` and
`.claude/skills/foo` becomes two `foo` skills from one package and aborts every
consumer's sync. Fix by declaring `extra.skills.source`, by git-ignoring the synced
copies, or by `export-ignore` on them. `scripts/check-project.php` detects this.

## External skill repositories

A package can point consumers at skills hosted elsewhere:

```json
{
    "extra": {
        "skills": {
            "sources": [
                { "from": "github", "package": "acme/orm-skills", "ref": "self.version" }
            ]
        }
    }
}
```

Same grammar as a project's `sources[]` ([sources.md](sources.md#source-entries)), with
`dir` excluded and `ref: "self.version"` added: it resolves to the installed package
version (`1.4.2` / `v1.4.2` tags, `dev-main` → `main`). The repository is fetched under
your package's trust, one level deep — its own `extra.skills.sources` are ignored.
Consumers can switch this off with `"vendor-sources": false`.

## Release checklist

The release is ready when all five hold:

1. `php <this-skill>/scripts/check-project.php` — no errors.
2. `composer skills:validate` — no errors.
3. The dist archive holds every shipped skill and no dev skill.
4. In a consumer project, `composer skills:show acme/orm` lists every shipped skill.
5. The README's "Agent skills" section covers every shipped skill and names none that is
   gone ([readme.md](readme.md)).
