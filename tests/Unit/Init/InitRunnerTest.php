<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Init;

use Composer\IO\BufferIO;
use Internal\Path;
use LLM\Skills\Config\InitOptions;
use LLM\Skills\Init\InitRunner;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Console\Command\Command;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Unit coverage for the `skills:init` runner: filesystem effects,
 * exit codes, and the contents it writes. End-to-end interaction with
 * Composer-rewriting via JsonManipulator is exercised in the
 * acceptance suite — here we focus on the runner's own decisions.
 */
#[Test]
final class InitRunnerTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-init-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function standaloneModeCreatesStubWithSchemaPointerOnly(): void
    {
        $io = new BufferIO();
        $code = (new InitRunner())->run(
            Path::create($this->tmp),
            $io,
            InitOptions::default(),
        );

        Assert::same($code, Command::SUCCESS);

        $written = $this->readSkillsJson();
        Assert::same(\array_keys($written), ['$schema']);
        Assert::same(
            $written['$schema'],
            InitRunner::SCHEMA_URL,
            '$schema pointer must use the published GitHub raw URL',
        );

        // Output advertises the mode so the user understands no composer.json
        // edits happened (because there is no composer.json).
        Assert::true(\str_contains($io->getOutput(), 'standalone mode'));
    }

    public function refusesToOverwriteExistingFileWithoutForce(): void
    {
        \file_put_contents($this->tmp . '/skills.json', '{"target":"existing"}');

        $io = new BufferIO();
        $code = (new InitRunner())->run(
            Path::create($this->tmp),
            $io,
            InitOptions::default(),
        );

        Assert::same($code, Command::FAILURE);
        // File must not be touched when refusal triggers.
        Assert::same(
            \file_get_contents($this->tmp . '/skills.json'),
            '{"target":"existing"}',
            'refusal must leave existing skills.json untouched',
        );
    }

    public function forceFlagOverwritesExistingFile(): void
    {
        \file_put_contents($this->tmp . '/skills.json', '{"target":"existing"}');

        $code = (new InitRunner())->run(
            Path::create($this->tmp),
            new BufferIO(),
            new InitOptions(force: true),
        );

        Assert::same($code, Command::SUCCESS);

        $written = $this->readSkillsJson();
        Assert::true(\array_key_exists('$schema', $written));
        Assert::false(
            \array_key_exists('target', $written),
            '--force in standalone mode regenerates a stub (no migrated keys)',
        );
    }

    public function absolutePathIsRejected(): void
    {
        $io = new BufferIO();
        $code = (new InitRunner())->run(
            Path::create($this->tmp),
            $io,
            new InitOptions(path: '/etc/skills.json'),
        );

        Assert::same($code, Command::INVALID);
        Assert::false(\is_file('/etc/skills.json'), 'must not touch an absolute path');
    }

    public function existingDirectoryAtTargetIsRejectedWithClearError(): void
    {
        // A pre-existing directory at the target path is not "overwrite this
        // file" territory — `file_put_contents` would later fail with a
        // generic "failed to write" message. The runner now catches this up
        // front and tells the user exactly what's wrong.
        \mkdir($this->tmp . '/skills.json', 0o777, true);

        $io = new BufferIO();
        $code = (new InitRunner())->run(
            Path::create($this->tmp),
            $io,
            InitOptions::default(),
        );

        Assert::same($code, Command::FAILURE);
        Assert::true(
            \str_contains($io->getOutput(), 'not a regular file'),
            'error must spell out the conflict; got: ' . $io->getOutput(),
        );
        // The directory must NOT have been touched.
        Assert::true(\is_dir($this->tmp . '/skills.json'));
    }

    public function escapingProjectRootIsRejected(): void
    {
        // `../escape.json` lexically resolves outside the project — the
        // containment guard rejects it before any write.
        $io = new BufferIO();
        $code = (new InitRunner())->run(
            Path::create($this->tmp),
            $io,
            new InitOptions(path: '../escape.json'),
        );

        Assert::same($code, Command::INVALID);
        Assert::false(\is_file(\dirname($this->tmp) . '/escape.json'));
    }

    public function composerAttachedModeMigratesProjectKeysAndCleansComposerJson(): void
    {
        $this->writeComposerJson([
            'name' => 'demo/consumer',
            'extra' => [
                'skills' => [
                    'target' => 'custom/skills',
                    'trusted' => ['acme/*'],
                    'auto-sync' => true,
                ],
            ],
        ]);

        $code = (new InitRunner())->run(
            Path::create($this->tmp),
            new BufferIO(),
            InitOptions::default(),
        );

        Assert::same($code, Command::SUCCESS);

        $skills = $this->readSkillsJson();
        Assert::same($skills['target'], 'custom/skills');
        Assert::same($skills['trusted'], ['acme/*']);
        Assert::same($skills['auto-sync'], true);

        // The migrated keys must be gone from composer.json.
        /** @var array{extra: array{skills?: array<string, mixed>}} $composer */
        $composer = \json_decode(
            (string) \file_get_contents($this->tmp . '/composer.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        $remainingSkills = $composer['extra']['skills'] ?? [];
        Assert::false(\array_key_exists('target', $remainingSkills));
        Assert::false(\array_key_exists('trusted', $remainingSkills));
        Assert::false(\array_key_exists('auto-sync', $remainingSkills));
    }

    public function composerAttachedModePreservesDonorSourceKey(): void
    {
        // A package can be both a donor (declares extra.skills.source) and
        // a consumer (declares project-level keys). `source` must stay
        // exactly where it is — it is not project config.
        $this->writeComposerJson([
            'name' => 'demo/dual',
            'extra' => [
                'skills' => [
                    'source' => 'resources/skills',
                    'target' => 'custom/skills',
                ],
            ],
        ]);

        $code = (new InitRunner())->run(
            Path::create($this->tmp),
            new BufferIO(),
            InitOptions::default(),
        );

        Assert::same($code, Command::SUCCESS);

        /** @var array{extra: array{skills: array<string, mixed>}} $composer */
        $composer = \json_decode(
            (string) \file_get_contents($this->tmp . '/composer.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        Assert::same(
            $composer['extra']['skills']['source'] ?? null,
            'resources/skills',
            'donor `source` key must remain in composer.json',
        );

        // And `source` is NOT in skills.json — that key is donor-side, not project.
        $skills = $this->readSkillsJson();
        Assert::false(\array_key_exists('source', $skills));
    }

    public function composerAttachedModeRefusesMalformedInlineBlock(): void
    {
        // Pre-flight catches the malformed inline config before any file
        // is written. Migrating "yes" into skills.json would just relocate
        // the same error.
        $this->writeComposerJson([
            'name' => 'demo/broken',
            'extra' => ['skills' => ['auto-sync' => 'yes']],
        ]);

        $io = new BufferIO();
        $code = (new InitRunner())->run(
            Path::create($this->tmp),
            $io,
            InitOptions::default(),
        );

        Assert::same($code, Command::FAILURE);
        Assert::false(
            \is_file($this->tmp . '/skills.json'),
            'no file must be written when the inline block is malformed',
        );
        Assert::true(\str_contains($io->getOutput(), 'inline extra.skills is malformed'));
    }

    public function composerAttachedModeStubWhenNoProjectKeys(): void
    {
        $this->writeComposerJson([
            'name' => 'demo/empty',
            'extra' => ['skills' => ['source' => 'resources/skills']],
        ]);

        $io = new BufferIO();
        $code = (new InitRunner())->run(
            Path::create($this->tmp),
            $io,
            InitOptions::default(),
        );

        Assert::same($code, Command::SUCCESS);
        Assert::same(\array_keys($this->readSkillsJson()), ['$schema']);
        Assert::true(\str_contains($io->getOutput(), 'no project keys to migrate'));
    }

    public function nonDefaultPathEmitsNotice(): void
    {
        // The loader only auto-discovers `skills.json`; warn the user when
        // they pick a different name so they don't silently lose
        // discovery on the next sync.
        $io = new BufferIO();
        $code = (new InitRunner())->run(
            Path::create($this->tmp),
            $io,
            new InitOptions(path: 'config/skills.json'),
        );

        Assert::same($code, Command::SUCCESS);
        Assert::true(\is_file($this->tmp . '/config/skills.json'));
        Assert::true(\str_contains($io->getOutput(), 'will not be discovered'));
    }

    public function nonDefaultPathWithInlineKeysMigratesAndRenames(): void
    {
        // Composer-attached + non-canonical `--path`: the migrator always
        // writes to <root>/skills.json; the runner then renames the file
        // to the requested location. End state: composer.json stripped,
        // file at the user-chosen path, no leftover canonical skills.json.
        $this->writeComposerJson([
            'name' => 'demo/non-canonical',
            'extra' => [
                'skills' => [
                    'target' => 'custom/skills',
                    'trusted' => ['acme/*'],
                ],
            ],
        ]);

        $io = new BufferIO();
        $code = (new InitRunner())->run(
            Path::create($this->tmp),
            $io,
            new InitOptions(path: 'config/skills.json'),
        );

        Assert::same($code, Command::SUCCESS);
        Assert::true(\is_file($this->tmp . '/config/skills.json'));
        Assert::false(
            \is_file($this->tmp . '/skills.json'),
            'canonical skills.json must have been renamed away',
        );

        // composer.json was stripped during the migration step.
        $composer = $this->readComposerJsonRaw();
        Assert::false(\array_key_exists('target', $composer['extra']['skills'] ?? []));
        Assert::false(\array_key_exists('trusted', $composer['extra']['skills'] ?? []));

        // Non-default path always carries the auto-discovery warning.
        Assert::true(\str_contains($io->getOutput(), 'will not be discovered'));
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposerJsonRaw(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode(
            (string) \file_get_contents($this->tmp . '/composer.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function readSkillsJson(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode(
            (string) \file_get_contents($this->tmp . '/skills.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeComposerJson(array $data): void
    {
        \file_put_contents(
            $this->tmp . '/composer.json',
            \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n",
        );
    }
}
