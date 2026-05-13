# `llm/skills` — Project Guide for AI Agents

`llm/skills` is a **Composer plugin** that discovers AI Skills bundled in vendor packages and
installs them into the consumer project (e.g. into `.claude/skills/`).

- Package type: `composer-plugin`
- Minimum PHP: **8.2**
- Root namespace: `LLM\Skills\` (PSR-4 → `src/`)
- Test namespace: `LLM\Skills\Tests\` (PSR-4 → `tests/`)
- Test framework: **testo** (not PHPUnit) — config in `testo.php`
- Mutation testing: **infection** with the `testo` test framework — config in `infection.json`
- Static analysis: **psalm** — config in `psalm.xml`
- Code style: **php-cs-fixer** with the `spiral/code-style` ruleset

## Repository Layout

```
src/                  Source code (LLM\Skills namespace)
├── Bootstrap.php       Container bootstrap entry point
├── Command/            Symfony Console commands (extend Command\Base)
├── Config/             Configuration objects, sources, attributes
├── Module/             Independent feature modules (e.g. Finder)
└── Service/            Core services: Container, Logger, Cache, …

tests/Unit/           Unit tests (mirror src/ structure)

resources/            Static resources shipped with the package
bin/ai                CLI entry point (also used for PHAR build via box.json)
docs/guidelines/      Engineering guidelines — see index below
runtime/              Build / test artefacts (gitignored)
```

## Guidelines Index

Always follow these guidelines before generating or editing code.

### Console Command Development
**Path:** [`docs/guidelines/how-to-write-console-command.md`](docs/guidelines/how-to-write-console-command.md)
**Value:** Ensures consistent CLI design built on Symfony Console and the project's `Container`.
**Key Areas:**
- Command structure: extend `LLM\Skills\Command\Base`, use `#[AsCommand]`
- Required methods: `configure()` + `execute()` (both call `parent::` first)
- Type system: prefer the `Internal\Path` value object over raw string paths
- Interactive patterns: use `$input->isInteractive()` rather than checking `--no-interaction`
- Error handling: return `Command::SUCCESS` / `FAILURE` / `INVALID` appropriately
- Services available via the container after `parent::execute()`

### PHP Code Standards
**Path:** [`docs/guidelines/how-to-write-php-code-best-practices.md`](docs/guidelines/how-to-write-php-code-best-practices.md)
**Value:** Maintains modern PHP code quality and leverages PHP 8.2+ features.
**Key Areas:**
- Modern PHP 8.2+: constructor promotion, readonly classes, match, throw expressions
- Code structure: PER-2, single responsibility, `final` by default, early returns
- Enumerations: CamelCase cases, backed enums for primitives, enum methods over `match` outside
- Immutability: readonly properties, `with*` prefix for immutable updates
- Type system: precise PHPDoc (`non-empty-string`, `list<T>`, generics)
- Comparison: strict equality (`===`), null coalescing (`??`), never `empty()`
- Dependency injection via constructor and the project `Container`

### Testing (Testo)
**Path:** [`docs/guidelines/how-to-write-tests.md`](docs/guidelines/how-to-write-tests.md)
**Value:** Ensures consistent, isolated tests that work with the testo framework.
**Key Areas:**
- Test structure: `tests/Unit` mirrors `src/`; `final` test classes
- Two styles: function-style (`function test*()`) and class-style (`#[Test]` methods)
- Assertions: `Testo\Assert::same/true/null/...` — **not** PHPUnit `self::assertX()`
- Data providers: `#[DataProvider]`, `#[DataSet]`, `#[DataCross]` attributes
- Lifecycle: `#[BeforeTest]` / `#[AfterTest]` / `#[BeforeClass]` / `#[AfterClass]` —
  **not** `setUp()` / `tearDown()`
- Modules: `tests/Unit/Module/{Name}/{Internal,Stub}/` mirrors `src/Module/{Name}/`
- **Critical restriction:** never mock `final` classes or enums — use real instances or
  hand-rolled stubs in a `Stub/` directory

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
- The `LLM\Assistant\…` namespace appearing in some files is legacy from an earlier project
  name and should be migrated to `LLM\Skills\…`; do not propagate it in new code.
- File paths in commands and services should flow through the `Internal\Path` value object
  (provided by the `internal/path` dependency).
