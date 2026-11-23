# Auditing third-party skills

Contents: when to audit · procedure · manual checklist · scanners · auditing updates ·
reporting.

An audit decides whether a skill may run with the user's privileges. Scanners are a
second opinion: published bypasses beat registry and open-source scanners alike with
newline padding, instructions hidden in a `.docx`, poisoned `.pyc` files, and prompt
injection aimed at LLM-based scanners. Opening every file is the bar.

## When to audit

- Before trusting a new package, vendor pattern or source.
- After `composer update` changes a skill of a trusted donor (`skills:show` shows `MOD`,
  or the committed target shows a diff).
- Before installing anything from a public registry or aggregator.

## Procedure

1. Get the files without installing them into the agent's reach: inspect the package in
   `vendor/`, clone the repository into a temporary directory, or run
   `composer skills:update <pkg> --dry-run` to see what would be copied.
2. Run `php <this-skill>/scripts/scan-skill.php <dir>` and read every finding.
3. Walk the checklist below over **every** file, not only `SKILL.md`.
4. Optionally run one of the scanners below as a second opinion.
5. Report to the user: provenance, what the skill does, each finding with file and line,
   and a recommendation — trust, trust with an allowlist and pinned ref, or reject.
   Granting trust is the user's decision.

The audit is complete when every file in the skill has been opened or explicitly
classified (binary, archive) and every checklist item has an answer.

## Manual checklist

1. **Provenance.** Publisher is the original author or an official org, not a repackaged
   mirror; account and repository have history; a license is present; the version is
   pinned to a tag or SHA.
2. **Inventory.** List every file. Archives (`.zip`, `.docx`, `.xlsx` are zips), compiled
   files (`.pyc`, `.so`, `.dll`, `.exe`, `.jar`), and hidden dotfiles need a justification
   or are a reject. Every bundled script gets read line by line.
3. **Hidden text.** Zero-width (U+200B–U+200D, U+2060, U+FEFF), bidi controls
   (U+202A–U+202E, U+2066–U+2069), Unicode tag characters (U+E0000–U+E007F), huge runs of
   blank lines or spaces, HTML comments in Markdown, long base64/hex blobs, `eval` of
   decoded strings.
4. **Prompt injection.** Imperatives unrelated to the skill's purpose; "ignore previous
   instructions", "do not tell the user", "this is pre-approved"; text dressed as
   `<IMPORTANT>`, system or policy blocks; orders to skip confirmations or pass
   `--yes` / `--dangerously-*` flags; orders to fetch further instructions from a URL or a
   bundled file.
5. **Install steps.** `curl … | sh`, `wget … | bash`, `iwr … | iex`, binary downloads,
   password-protected archives, "run this setup first" prerequisites, registry overrides
   (`npm config set registry`, `pip --index-url`, Composer `repositories`) — the pattern
   behind the ClawHavoc stealer campaign.
6. **Secrets.** Reads of `.env`, `~/.ssh`, `~/.aws`, `~/.config/gh`, `auth.json`, browser
   profiles, keychains, agent or MCP configs; dumps of the environment.
7. **Network.** Every outbound call (`curl`, `fetch`, `requests`, `Invoke-WebRequest`,
   `file_get_contents('http…')`) must be explained by the skill's purpose. Webhooks,
   pastebins, raw IPs and URL shorteners are red flags.
8. **Persistence.** Writes to shell rc files, cron, git hooks, `AGENTS.md`, `CLAUDE.md`,
   other skills, agent settings or permission files; `sudo`, `chmod +x` on downloads,
   `LD_PRELOAD`.
9. **Frontmatter.** `allowed-tools` pre-approves tools — `Bash`, write or network access in
   a skill that should only produce text is a red flag. An over-broad `description` makes
   the skill fire on unrelated tasks.
10. **Claim vs behaviour.** The code does only what the description says. Risky but
    legitimate code still gets named in the report.

## Scanners

Lightest first. All are third-party tools the user installs; ask before installing.

| Tool | Install | Run | Notes |
|---|---|---|---|
| `scan-skill.php` | bundled | `php <this-skill>/scripts/scan-skill.php <dir>` | Deterministic pre-screen, offline |
| Cisco AI Defense skill-scanner | `uv tool install cisco-ai-skill-scanner` | `skill-scanner scan-all <dir> --recursive` | Offline and free by default (YARA, taint, `.pyc` integrity); optional LLM, VirusTotal |
| NVIDIA SkillSpector | `uv tool install --python 3.13 git+https://github.com/NVIDIA/skillspector.git` | `skillspector scan <dir> --no-llm` | Static AST/taint/YARA + OSV dependency lookup; `--format sarif`; also an MCP server (`skillspector mcp`) |
| Snyk agent-scan | none (`uvx`) | `uvx snyk-agent-scan@latest <dir>` | Needs `SNYK_TOKEN`; **sends skill content to Snyk's cloud** — confirm with the user first |
| SkillSpector or Cisco with the LLM stage | as above | SkillSpector: drop `--no-llm`, set `SKILLSPECTOR_PROVIDER`; Cisco: `--use-llm` | Semantic claim-vs-code review; hosted models, or local via Ollama / vLLM / llama.cpp — the only place a GPU helps |

SkillSpector is the scanner NVIDIA gates its own skills catalog with; it runs on a CPU,
and its LLM stage uses whichever provider you configure. Format validators
(`skills-ref validate`, `composer skills:lint`) check structure, not safety.

Registries publish their own verdicts — skills.sh shows Gen Agent Trust Hub, Socket and
Snyk results per skill, ClawHub shows VirusTotal status. Treat them as one signal.

## Auditing updates

Content can change after approval. For a trusted donor:

- Committed target: review the diff of the target directory after `composer update`.
- Ignored target: `composer skills:show` flags `MOD` (drift) and `NEW`; diff the package
  between versions (`vendor/<pkg>` before and after, or the repository's tag compare).
- Remote sources: pin `ref` and re-audit when bumping it
  ([sources.md](sources.md#refs-and-caching)).

## Reporting

Structure the report as: **verdict** (trust / trust narrowly / reject) · provenance ·
inventory · findings by checklist item with `file:line` · scanner output summary · the exact
config change that would grant the recommended trust.
