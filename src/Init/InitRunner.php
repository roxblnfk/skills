<?php

declare(strict_types=1);

namespace LLM\Skills\Init;

use Composer\IO\IOInterface;
use Composer\Json\JsonManipulator;
use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\InitOptions;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use Symfony\Component\Console\Command\Command;

/**
 * Body of the `skills:init` command — independent of which entrypoint
 * invoked it.
 *
 * Detects whether `composer.json` exists at the project root and runs
 * one of two flows:
 *
 * - **Composer-attached** — read the inline `extra.skills` block,
 *   partition its keys into project vs donor, write the project keys
 *   into a fresh `skills.json`, and rewrite `composer.json` to drop the
 *   migrated project keys (donor `source` and any unrelated `extra`
 *   keys are left in place).
 * - **Standalone** — write a stub `skills.json` (only `$schema`) and
 *   touch nothing else.
 *
 * The flow is **atomic on success**: validation happens before any
 * filesystem write, and the two writes (skills.json, composer.json)
 * run in fixed order — `skills.json` first, so a crash before the
 * second write leaves the new file plus the original `composer.json`
 * intact. A re-run with `--force` recovers from any partial state.
 */
final readonly class InitRunner
{
    /**
     * URL of the published JSON schema. Emitted into the generated file's
     * `$schema` field so editors that follow the link can offer
     * validation / autocompletion.
     */
    public const SCHEMA_URL = 'https://raw.githubusercontent.com/roxblnfk/skills/master/resources/skills.schema.json';

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private ProjectConfigMapper $projectMapper = new ProjectConfigMapper(),
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

        // A pre-existing directory (or other non-file) at the target path
        // cannot be overwritten by writing a file — let the user know
        // explicitly rather than letting `file_put_contents` fail later
        // with a generic message.
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

        $composerJsonPath = (string) $projectRoot->join('composer.json');
        if (!\is_file($composerJsonPath)) {
            return $this->runStandalone($io, $options, $target);
        }

        return $this->runComposerAttached($projectRoot, $composerJsonPath, $io, $options, $target);
    }

    /**
     * @param non-empty-string $target absolute path to the skills.json being created
     */
    private function runStandalone(IOInterface $io, InitOptions $options, string $target): int
    {
        $content = $this->renderSkillsJson([]);
        if (!$this->writeFile($target, $content, $io)) {
            return Command::FAILURE;
        }

        $io->write('<info>[init]</info> standalone mode (no composer.json detected)');
        $io->write(\sprintf('<info>[init]</info> created %s', $options->path));
        $io->write('<info>[init]</info> note: subsequent skills commands will read this file directly');

        $this->maybeWarnNonDefaultPath($io, $options->path);

        return Command::SUCCESS;
    }

    /**
     * @param non-empty-string $composerJsonPath
     * @param non-empty-string $target absolute path to the skills.json being created
     */
    private function runComposerAttached(
        Path $projectRoot,
        string $composerJsonPath,
        IOInterface $io,
        InitOptions $options,
        string $target,
    ): int {
        $original = \file_get_contents($composerJsonPath);
        if ($original === false) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] failed to read composer.json at %s</error>',
                $composerJsonPath,
            ));
            return Command::FAILURE;
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($original, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] composer.json is not valid JSON: %s</error>',
                $e->getMessage(),
            ));
            return Command::FAILURE;
        }

        if (!\is_array($decoded)) {
            $io->writeError('<error>[llm/skills] composer.json must be a JSON object</error>');
            return Command::FAILURE;
        }

        /** @var array<string, mixed> $extra */
        $extra = \is_array($decoded['extra'] ?? null) ? $decoded['extra'] : [];
        /** @var array<string, mixed> $skills */
        $skills = \is_array($extra['skills'] ?? null) ? $extra['skills'] : [];

        // Pre-flight: confirm the inline block is well-formed. Migrating
        // a malformed config silently would just relocate the bug into
        // skills.json where it would surface on the next sync — fail loud
        // up front instead.
        try {
            $this->projectMapper->fromExtra($extra);
        } catch (MalformedProjectConfig $e) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] cannot migrate: inline extra.skills is malformed — %s</error>',
                $e->getMessage(),
            ));
            return Command::FAILURE;
        }

        $migrated = $this->extractProjectKeys($skills);

        // Validate that the future skills.json itself maps cleanly — a
        // second safety net in case extractProjectKeys reshapes anything
        // (it currently does not, but the cost of running it is trivial).
        try {
            $this->projectMapper->fromExtra(['skills' => $migrated]);
        } catch (MalformedProjectConfig $e) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] migrated content is not a valid skills.json: %s</error>',
                $e->getMessage(),
            ));
            return Command::FAILURE;
        }

        $content = $this->renderSkillsJson($migrated);

        // Write skills.json first. If composer.json rewrite then fails,
        // re-running with --force fixes the partial state.
        if (!$this->writeFile($target, $content, $io)) {
            return Command::FAILURE;
        }

        $migratedKeys = \array_keys($migrated);
        if ($migratedKeys !== []) {
            $manipulator = new JsonManipulator($original);
            foreach ($migratedKeys as $key) {
                if (!$manipulator->removeSubNode('extra', 'skills.' . $key)) {
                    $io->writeError(\sprintf(
                        '<error>[llm/skills] failed to remove extra.skills.%s from composer.json '
                        . '(skills.json was written; re-run with --force after fixing composer.json)</error>',
                        $key,
                    ));
                    return Command::FAILURE;
                }
            }

            $newComposer = $manipulator->getContents();
            if (\file_put_contents($composerJsonPath, $newComposer) === false) {
                $io->writeError(\sprintf(
                    '<error>[llm/skills] failed to write composer.json at %s</error>',
                    $composerJsonPath,
                ));
                return Command::FAILURE;
            }
        }

        $this->emitComposerAttachedReport($io, $options->path, $migratedKeys);
        $this->maybeWarnNonDefaultPath($io, $options->path);

        return Command::SUCCESS;
    }

    /**
     * @param non-empty-string $target absolute filesystem path
     */
    private function writeFile(string $target, string $content, IOInterface $io): bool
    {
        $dir = \dirname($target);
        if (!\is_dir($dir) && !@\mkdir($dir, 0o777, true) && !\is_dir($dir)) {
            $io->writeError(\sprintf(
                '<error>[llm/skills] failed to create directory %s</error>',
                $dir,
            ));
            return false;
        }

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
     * Resolve `$raw` against the project root and verify the result stays
     * inside it.
     *
     * Reuses the {@see Path::match()} containment idiom that
     * {@see \LLM\Skills\Sync\SyncPlanner::assertWithinProject()} uses so
     * semantics stay aligned. `null` means the input is not acceptable
     * (absolute path or `..`-escape) — caller turns that into a fatal
     * error.
     *
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
     * Extract the project-level keys (the ones documented in
     * {@see ProjectConfigMapper::PROJECT_KEYS}) from the inline
     * `extra.skills` block. Donor `source` and any unrelated keys are
     * deliberately dropped from the result.
     *
     * @param array<array-key, mixed> $skills the inline `extra.skills` value
     *
     * @return array<non-empty-string, mixed> ordered per {@see ProjectConfigMapper::PROJECT_KEYS}
     *
     * @psalm-pure
     */
    private function extractProjectKeys(array $skills): array
    {
        $out = [];
        foreach (ProjectConfigMapper::PROJECT_KEYS as $key) {
            if (\array_key_exists($key, $skills)) {
                /** @psalm-suppress MixedAssignment value type intentionally unknown until mapper validates */
                $out[$key] = $skills[$key];
            }
        }

        return $out;
    }

    /**
     * Emit the file content for a new `skills.json`. Defaults are NOT
     * written — only the keys the user actually customised. Always
     * starts with a `$schema` pointer so editors can validate the file.
     *
     * @param array<string, mixed> $migrated project keys in {@see ProjectConfigMapper::PROJECT_KEYS} order
     *
     * @psalm-pure
     */
    private function renderSkillsJson(array $migrated): string
    {
        $payload = ['$schema' => self::SCHEMA_URL] + $migrated;

        $json = \json_encode(
            $payload,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        if ($json === false) {
            throw new \RuntimeException('Failed to encode skills.json payload');
        }

        return $json . "\n";
    }

    /**
     * @param list<non-empty-string> $migratedKeys
     */
    private function emitComposerAttachedReport(IOInterface $io, string $path, array $migratedKeys): void
    {
        if ($migratedKeys === []) {
            $io->write(\sprintf(
                '<info>[init]</info> created %s (no project keys to migrate; stub created)',
                $path,
            ));
            $io->write('<info>[init]</info> note: skills:update will now read project config from skills.json');
            return;
        }

        $io->write(\sprintf(
            '<info>[init]</info> created %s (migrated: %s)',
            $path,
            \implode(', ', $migratedKeys),
        ));
        $io->write(
            '<info>[init]</info> composer.json updated: removed migrated project keys from extra.skills',
        );
        $io->write('<info>[init]</info> note: skills:update will now read project config from skills.json');
    }

    /**
     * Warn the user when `--path` is anything other than the canonical
     * `skills.json`. The loader only looks at that exact filename at the
     * project root, so a custom location is created but won't be picked
     * up by subsequent commands.
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
