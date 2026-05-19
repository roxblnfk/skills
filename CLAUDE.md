# `llm/skills` — Project Guide for AI Agents

`llm/skills` is a **Composer plugin** that discovers AI Skills bundled in vendor packages and
installs them into the consumer project (default `.agents/skills/`, configurable per project).

- Package type: `composer-plugin`
- Minimum PHP: **8.2**
- Root namespace: `LLM\Skills\` (PSR-4 → `src/`)
- Test namespace: `LLM\Skills\Tests\` (PSR-4 → `tests/`)
- Test framework: **testo** (not PHPUnit) — config in `testo.php`
- Mutation testing: **infection** with the `testo` test framework — config in `infection.json`
- Static analysis: **psalm** — config in `psalm.xml`
- Code style: **php-cs-fixer** with the `spiral/code-style` ruleset

## Common Tasks (composer scripts)

| Task                | Command                       |
|---------------------|-------------------------------|
| Run tests           | `composer test`               |
| Static analysis     | `composer psalm`              |
| Code style — diff   | `composer cs:diff`            |
| Code style — fix    | `composer cs:fix`             |

## Notes for Agents

- Do **not** introduce PHPUnit-style tests — this project uses testo. If you see
  `self::assertX()` it is wrong code, not a pattern to copy.
- The `LLM\Skills\…` namespace appearing in some files is legacy from an earlier project
  name and should be migrated to `LLM\Skills\…`; do not propagate it in new code.
- File paths in commands and services should flow through the `Internal\Path` value object
  (provided by the `internal/path` dependency).
- **Behavioural changes require acceptance coverage.** Whenever a change alters
  observable plugin behaviour — what gets discovered, copied, skipped, warned
  about, or how output looks — add or update an acceptance test in
  `tests/Acceptance/` alongside the unit test. The sandbox project at
  `tests/Sandbox/project/` exists for exactly this; introduce a new fixture
  package under `tests/Sandbox/packages/<vendor>/<name>/` when an existing
  fixture cannot express the scenario, wire it into the sandbox's
  `composer.json`, and run `composer update <vendor>/<name>` inside the
  sandbox so `installed.json` reflects the new metadata. Unit tests alone are
  not enough: they pass the mapper, miss the end-to-end pipeline.
