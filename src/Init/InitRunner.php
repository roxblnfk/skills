<?php

declare(strict_types=1);

namespace LLM\Skills\Init;

use Composer\IO\IOInterface;
use Internal\Path;
use LLM\Skills\Config\InitOptions;
use LLM\Skills\Config\Mapper\MigrationStatus;
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
        // circuit the rewrite the user explicitly asked for.
        if ($options->force && $options->path === 'skills.json' && \is_file($target)) {
            @\unlink($target);
        }

        $composerJsonPath = (string) $projectRoot->join('composer.json');
        if (!\is_file($composerJsonPath)) {
            return $this->runStandalone($io, $options, $target);
        }

        // Canonical migration path. Non-default `--path` requires
        // post-processing (rename) since the migrator always writes
        // to <root>/skills.json — see {@see self::handleNonDefaultPath()}.
        if ($options->path !== 'skills.json') {
            return $this->runNonDefaultPath($projectRoot, $io, $options, $target);
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
     * @param non-empty-string $target
     */
    private function runStandalone(IOInterface $io, InitOptions $options, string $target): int
    {
        if (!$this->writeStub($target, $io)) {
            return Command::FAILURE;
        }

        $io->write('<info>[init]</info> standalone mode (no composer.json detected)');
        $io->write(\sprintf('<info>[init]</info> created %s', $options->path));
        $io->write('<info>[init]</info> note: subsequent skills commands will read this file directly');

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

        $content = ProjectConfigMigrator::renderSkillsJson([]);
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
