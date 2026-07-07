<?php

declare(strict_types=1);

namespace LLM\Skills\Init;

use Composer\IO\IOInterface;
use Composer\Json\JsonManipulator;
use Internal\Path;
use LLM\Skills\Config\InitOptions;
use LLM\Skills\Config\Mapper\MigrationStatus;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Config\Mapper\ProjectConfigMigrator;
use Symfony\Component\Console\Command\Command;

/**
 * Body of the `skills:init` command — independent of which entrypoint
 * invoked it.
 *
 * Composer-attached projects: delegate to {@see ProjectConfigMigrator}.
 * Same code path the auto-migration in `skills:update` uses; running
 * `skills:init` explicitly is just the fast way to perform the
 * migration without doing a full sync.
 *
 * Standalone projects (no `composer.json` at cwd): write a stub
 * `skills.json` containing only the `$schema` pointer so the user has
 * a starting point.
 *
 * The `--path` flag lets the user pick a non-canonical location for
 * the generated file. Subsequent commands only look at the canonical
 * `skills.json` at the project root, so a non-default `--path` also
 * emits a notice — it's a "create, then move it yourself" affordance.
 *
 * The migrator handles the composer-attached refusal logic
 * implicitly (skills.json already exists → no-op); InitRunner adds
 * the user-facing refusal-without-force semantics on top.
 */
final readonly class InitRunner
{
    /**
     * URL of the published JSON schema. Kept as a class constant for
     * the standalone-stub path (the migrator owns the same value for
     * the migration path).
     */
    public const SCHEMA_URL = ProjectConfigMigrator::SCHEMA_URL;

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private ProjectConfigMigrator $migrator = new ProjectConfigMigrator(),
        private InteractiveInitWizard $wizard = new InteractiveInitWizard(),
    ) {}

    public function run(Path $projectRoot, IOInterface $io, InitOptions $options): int
    {
        $target = $this->resolveTargetPath($projectRoot, $options->path);
        if ($target === null) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] --path "%s" must be a relative path inside the project root</error>',
                $options->path,
            ));
            return Command::INVALID;
        }

        if (\file_exists($target) && !\is_file($target)) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] %s exists but is not a regular file; '
                . 'cannot write skills.json there. Choose a different --path or remove it.</error>',
                $options->path,
            ));
            return Command::FAILURE;
        }

        if (\is_file($target) && !$options->force) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] %s already exists; pass --force to overwrite</error>',
                $options->path,
            ));
            return Command::FAILURE;
        }

        // `--force` re-runs in the canonical location are handled by
        // unlinking the existing file first so the migrator's
        // "skills.json already exists → skip" branch does not short-
        // circuit the rewrite the user explicitly asked for. For the
        // interactive path we hold off on the unlink so we can still
        // read the existing skills.json values as defaults — handled
        // inside runInteractive() instead.
        if (
            !$io->isInteractive()
            && $options->force
            && $options->path === 'skills.json'
            && \is_file($target)
        ) {
            // Suppress so we can report a friendlier message ourselves
            // (permission denied / file lock on Windows surfaces as a
            // PHP warning otherwise). The follow-up `is_file` check is
            // what actually decides whether to fail the run — a
            // successful unlink leaves no file, period.
            @\unlink($target);
            if (\is_file($target)) {
                $io->writeError(\sprintf(
                    '<error>[llm/skills] --force could not remove existing %s (file locked or permission denied). '
                    . 'Remove it manually and re-run.</error>',
                    $target,
                ));
                return Command::FAILURE;
            }
        }

        $composerJsonPath = (string) $projectRoot->join('composer.json');

        // `skills:init` is the command users run on purpose — it exists
        // precisely because they want to think about their config. In
        // interactive mode, walk them through the wizard with sensible
        // defaults. CI / `--no-interaction` keeps the silent flow.
        if ($io->isInteractive() && $options->path === 'skills.json') {
            return $this->runInteractive(
                $projectRoot,
                $io,
                $options,
                $target,
                \is_file($composerJsonPath) ? $composerJsonPath : null,
            );
        }

        if (!\is_file($composerJsonPath)) {
            return $this->runStandalone($io, $options, $target);
        }

        // Canonical migration path. Non-default `--path` requires
        // post-processing (rename) since the migrator always writes
        // to <root>/skills.json — see {@see self::runNonDefaultPath()}.
        if ($options->path !== 'skills.json') {
            return $this->runNonDefaultPath($projectRoot, $io, $options, $target);
        }

        if ($this->migrator->renameSourcesKey($projectRoot, $io)->status === MigrationStatus::Failed) {
            return Command::FAILURE;
        }

        $result = $this->migrator->migrate($projectRoot, $io);

        switch ($result->status) {
            case MigrationStatus::Failed:
                return Command::FAILURE;

            case MigrationStatus::Skipped:
                // Either skills.json already exists (handled above when
                // --force not set) or there are no inline keys to
                // migrate. Either way, ensure a stub exists so the
                // user can start adding things.
                if (!\is_file($target)) {
                    if (!$this->writeStub($target, $io)) {
                        return Command::FAILURE;
                    }
                    $io->write(\sprintf(
                        '<info>[init]</info> created %s (no project keys to migrate; stub created)',
                        $options->path,
                    ));
                    $io->write('<info>[init]</info> note: skills:update will read project config from skills.json');
                }
                return Command::SUCCESS;

            case MigrationStatus::Migrated:
                $io->write(\sprintf(
                    '<info>[init]</info> created %s (migrated: %s)',
                    $options->path,
                    \implode(', ', $result->migratedKeys),
                ));
                $io->write(
                    '<info>[init]</info> composer.json updated: removed migrated project keys from extra.skills',
                );
                $io->write('<info>[init]</info> note: skills:update will read project config from skills.json');
                return Command::SUCCESS;
        }
    }

    /**
     * Interactive flow. Resolves defaults from (priority order):
     *
     * 1. The existing `skills.json` when `--force` re-runs on top of
     *    one — current values become the prompts' defaults so users
     *    can tune knob-by-knob.
     * 2. The inline `extra.skills` block in `composer.json` — only
     *    when the user confirms "import current settings?" at the
     *    top of the wizard.
     * 3. {@see ProjectConfig::default()}.
     *
     * After the wizard returns: writes `skills.json` with the user's
     * answers, then strips the inline keys from `composer.json` (if
     * the file existed and carried project keys). Cancelling at the
     * final confirmation aborts cleanly without writing anything.
     *
     * @param non-empty-string $target
     * @param non-empty-string|null $composerJsonPath
     */
    private function runInteractive(
        Path $projectRoot,
        IOInterface $io,
        InitOptions $options,
        string $target,
        ?string $composerJsonPath,
    ): int {
        $defaults = [];

        // Existing skills.json (only under --force; the early refusal
        // check would have failed otherwise).
        if (\is_file($target)) {
            $existing = $this->readJsonObject($target, $io, 'skills.json');
            if ($existing === null) {
                return Command::FAILURE;
            }
            unset($existing['$schema']);
            $defaults = $existing;
        }

        $inlineKeys = [];
        if ($composerJsonPath !== null && $defaults === []) {
            $composerDecoded = $this->readJsonObject($composerJsonPath, $io, 'composer.json');
            if ($composerDecoded === null) {
                return Command::FAILURE;
            }
            /** @var array<string, mixed> $extra */
            $extra = \is_array($composerDecoded['extra'] ?? null) ? $composerDecoded['extra'] : [];
            /** @var array<string, mixed> $skills */
            $skills = \is_array($extra['skills'] ?? null) ? $extra['skills'] : [];
            // Defaults fold a `remote` alias into `sources`; the strip
            // list keeps the original key names so composer.json editing
            // targets the keys that are actually there.
            $inlineDefaults = ProjectConfigMigrator::extractProjectKeys($skills);
            $inlineKeys = ProjectConfigMigrator::presentProjectKeys($skills);

            if ($inlineKeys !== []) {
                $io->write(\sprintf(
                    '<info>[init]</info> detected inline extra.skills in composer.json: %s',
                    \implode(', ', $inlineKeys),
                ));
                if ($io->askConfirmation(
                    '<info>Import these as defaults?</info> [<comment>Y/n</comment>]: ',
                    true,
                )) {
                    $defaults = $inlineDefaults;
                }
            }
        }

        $resolved = $this->wizard->run($io, $defaults);
        if ($resolved === null) {
            return Command::SUCCESS;
        }

        $content = ProjectConfigMigrator::renderSkillsJson($this->orderedProjectKeys($resolved));
        if (\file_put_contents($target, $content) === false) {
            $io->writeError(\sprintf('<error>[llm/skills] failed to write %s</error>', $target));
            return Command::FAILURE;
        }

        // Strip inline keys from composer.json if any are present. We
        // re-read composer.json here (instead of reusing the
        // earlier decode) because JsonManipulator needs the raw bytes
        // for in-place editing. We also pass the full original `$skills`
        // block so the helper can collapse an empty `"skills": {}`
        // residue (and an empty `"extra": {}`) after the strip.
        if ($composerJsonPath !== null && $inlineKeys !== []) {
            /** @var array<string, mixed> $skills */
            if (!$this->stripInlineKeys($composerJsonPath, $skills, $inlineKeys, $io)) {
                $io->write(
                    '<comment>[init] note: skills.json was written, but stripping '
                    . 'composer.json failed. skills.json wins from now on regardless.</comment>',
                );
                return Command::FAILURE;
            }
            $io->write('<info>[init]</info> composer.json updated: removed inline project keys.');
        }

        $io->write(\sprintf('<info>[init]</info> wrote %s', $options->path));
        $io->write('<info>[init]</info> done.');

        return Command::SUCCESS;
    }

    /**
     * Re-order an arbitrary project-keys map to match
     * {@see ProjectConfigMapper::PROJECT_KEYS} so generated files are
     * diff-stable across runs.
     *
     * @param array<string, mixed> $values
     *
     * @return array<non-empty-string, mixed>
     *
     * @psalm-pure
     */
    private function orderedProjectKeys(array $values): array
    {
        $out = [];
        foreach (ProjectConfigMapper::PROJECT_KEYS as $key) {
            if (\array_key_exists($key, $values)) {
                /** @psalm-suppress MixedAssignment */
                $out[$key] = $values[$key];
            }
        }
        return $out;
    }

    /**
     * @param non-empty-string $path
     * @param non-empty-string $label used in error messages
     *
     * @return array<string, mixed>|null
     */
    private function readJsonObject(string $path, IOInterface $io, string $label): ?array
    {
        $raw = \file_get_contents($path);
        if ($raw === false) {
            $io->writeError(\sprintf('<error>[llm/skills] failed to read %s</error>', $label));
            return null;
        }
        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] %s is not valid JSON: %s</error>',
                $label,
                $e->getMessage(),
            ));
            return null;
        }
        if (!\is_array($decoded)) {
            $io->writeError(\sprintf('<error>[llm/skills] %s must be a JSON object</error>', $label));
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param non-empty-string $composerJsonPath
     * @param array<string, mixed> $originalSkills the inline `extra.skills` block as it
     *        existed before the strip; used to decide whether to collapse an
     *        empty `"skills": {}` residue (and a now-empty `"extra": {}`)
     * @param list<non-empty-string> $keys
     */
    private function stripInlineKeys(
        string $composerJsonPath,
        array $originalSkills,
        array $keys,
        IOInterface $io,
    ): bool {
        $raw = \file_get_contents($composerJsonPath);
        if ($raw === false) {
            $io->writeError('<error>[llm/skills] failed to re-read composer.json for cleanup</error>');
            return false;
        }
        $manipulator = new JsonManipulator($raw);
        foreach ($keys as $key) {
            if (!$manipulator->removeSubNode('extra', 'skills.' . $key)) {
                $io->writeError(\sprintf(
                    '<error>[llm/skills] JsonManipulator failed on extra.skills.%s</error>',
                    $key,
                ));
                return false;
            }
        }

        // If the `extra.skills` block had nothing but the keys we just
        // stripped, the leftover `"skills": {}` is dead weight — strip
        // it too. Same hygiene for `"extra": {}`.
        $remaining = \array_diff(\array_keys($originalSkills), $keys);
        if ($remaining === []) {
            $manipulator->removeSubNode('extra', 'skills');
            $manipulator->removeMainKeyIfEmpty('extra');
        }

        if (\file_put_contents($composerJsonPath, $manipulator->getContents()) === false) {
            $io->writeError('<error>[llm/skills] failed to write composer.json</error>');
            return false;
        }
        return true;
    }

    /**
     * @param non-empty-string $target
     */
    private function runStandalone(IOInterface $io, InitOptions $options, string $target): int
    {
        if (!$this->writeStub($target, $io)) {
            return Command::FAILURE;
        }

        $io->write('<info>[init]</info> standalone mode (no composer.json detected)');
        $io->write(\sprintf('<info>[init]</info> created %s', $options->path));

        // The "subsequent commands will read this file" promise only holds
        // when the file lives at the canonical location. For a custom
        // --path, the `maybeWarnNonDefaultPath()` notice below contradicts
        // it — emit one or the other, never both.
        if ($options->path === 'skills.json') {
            $io->write('<info>[init]</info> note: subsequent skills commands will read this file directly');
        }

        $this->maybeWarnNonDefaultPath($io, $options->path);

        return Command::SUCCESS;
    }

    /**
     * Composer-attached + non-canonical `--path`. The migrator always
     * writes to `skills.json` at the project root, so we let it do
     * its job and then move the file to the requested location.
     * Always followed by the "won't be auto-discovered" notice.
     *
     * @param non-empty-string $target
     */
    private function runNonDefaultPath(
        Path $projectRoot,
        IOInterface $io,
        InitOptions $options,
        string $target,
    ): int {
        $canonical = (string) $projectRoot->join('skills.json');
        $canonicalExisted = \is_file($canonical);

        if ($this->migrator->renameSourcesKey($projectRoot, $io)->status === MigrationStatus::Failed) {
            return Command::FAILURE;
        }

        $result = $this->migrator->migrate($projectRoot, $io);

        if ($result->status === MigrationStatus::Failed) {
            return Command::FAILURE;
        }

        // Either the migrator wrote skills.json or it was already
        // there. In the latter case we still need a file at $target;
        // create a stub for it.
        if ($result->status === MigrationStatus::Migrated) {
            // The migrator always lands its output at the canonical
            // path; ensure the parent of the user-chosen path exists
            // before renaming, otherwise the rename will silently fail
            // on a non-existent subdirectory.
            $dir = \dirname($target);
            if (!\is_dir($dir) && !@\mkdir($dir, 0o777, true) && !\is_dir($dir)) {
                $io->writeError(\sprintf(
                    '<error>[llm/skills] failed to create directory %s</error>',
                    $dir,
                ));
                return Command::FAILURE;
            }
            // POSIX `rename()` overwrites an existing destination
            // transparently, but Windows refuses to. When the user
            // passed `--force` and a file at the target survived the
            // earlier refusal check, unlink it explicitly so `--force`
            // is honoured the same way on both platforms.
            if ($options->force && \is_file($target)) {
                @\unlink($target);
            }
            if (!@\rename($canonical, $target)) {
                $io->writeError(\sprintf(
                    '<error>[llm/skills] failed to relocate skills.json to %s</error>',
                    $options->path,
                ));
                return Command::FAILURE;
            }
            $io->write(\sprintf(
                '<info>[init]</info> created %s (migrated: %s)',
                $options->path,
                \implode(', ', $result->migratedKeys),
            ));
            $io->write(
                '<info>[init]</info> composer.json updated: removed migrated project keys from extra.skills',
            );
        } else {
            // Skipped → stub at $target. If skills.json at the canonical
            // location was created by an earlier explicit run we leave
            // it; otherwise nothing else to clean up.
            if (!$canonicalExisted && \is_file($canonical)) {
                @\unlink($canonical);
            }
            if (!$this->writeStub($target, $io)) {
                return Command::FAILURE;
            }
            $io->write(\sprintf(
                '<info>[init]</info> created %s (no project keys to migrate; stub created)',
                $options->path,
            ));
        }

        $this->maybeWarnNonDefaultPath($io, $options->path);

        return Command::SUCCESS;
    }

    /**
     * @param non-empty-string $target
     */
    private function writeStub(string $target, IOInterface $io): bool
    {
        $dir = \dirname($target);
        if (!\is_dir($dir) && !@\mkdir($dir, 0o777, true) && !\is_dir($dir)) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] failed to create directory %s</error>',
                $dir,
            ));
            return false;
        }

        // Stub `skills.json` ships with the `local` and `remote`
        // knobs visible so users discover them without reading docs.
        // `local.composer: true` is also the default, but we emit it
        // explicitly — hiding it would make the npm / go toggles seem
        // surprise-feature-y when they arrive.
        $content = ProjectConfigMigrator::renderSkillsJson([
            'local' => ['composer' => true],
            'remote' => [],
        ]);
        if (\file_put_contents($target, $content) === false) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] failed to write %s</error>',
                $target,
            ));
            return false;
        }

        return true;
    }

    /**
     * @return non-empty-string|null absolute filesystem path or `null` if input is invalid
     */
    private function resolveTargetPath(Path $projectRoot, string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        $rawPath = Path::create($raw);
        if ($rawPath->isAbsolute()) {
            return null;
        }

        $resolved = $projectRoot->join($rawPath);
        if (!$resolved->match($projectRoot->join('*'))) {
            return null;
        }

        return (string) $resolved;
    }

    /**
     * The loader only auto-discovers `skills.json` at the project root.
     * A custom location is created but won't be picked up by subsequent
     * commands — surface that explicitly so the user is not surprised.
     */
    private function maybeWarnNonDefaultPath(IOInterface $io, string $path): void
    {
        if ($path === 'skills.json') {
            return;
        }

        $io->writeError(\sprintf(
            '<comment>[init] note: only "skills.json" at the project root is auto-loaded by '
            . 'subsequent commands; "%s" was written but will not be discovered. '
            . 'Move or rename it to skills.json to activate.</comment>',
            $path,
        ));
    }
}
