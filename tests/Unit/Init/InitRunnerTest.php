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

    public function standaloneModeCreatesStubWithSchemaAndProviderDefaults(): void
    {
        // The stub advertises the `dependencies` and `sources` knobs
        // explicitly, even when their values match the defaults, so users
        // discover them without reading docs.
        $io = new BufferIO();
        $code = (new InitRunner())->run(
            Path::create($this->tmp),
            $io,
            InitOptions::default(),
        );

        Assert::same($code, Command::SUCCESS);

        $written = $this->readSkillsJson();
        Assert::same(\array_keys($written), ['$schema', 'dependencies', 'sources']);
        Assert::same(
            $written['$schema'],
            InitRunner::SCHEMA_URL,
            '$schema pointer must use the published GitHub raw URL',
        );
        Assert::same($written['dependencies'], ['composer' => true]);
        Assert::same($written['sources'], []);

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
        // Flat `trusted` folds into the `dependencies.composer` block;
        // the migrated file never carries the legacy key.
        Assert::false(\array_key_exists('trusted', $skills));
        Assert::same($skills['dependencies'], ['composer' => ['trusted' => ['acme/*']]]);
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
        Assert::same(\array_keys($this->readSkillsJson()), ['$schema', 'dependencies', 'sources']);
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

    public function nonDefaultPathWithForceOverwritesExistingTargetAfterMigration(): void
    {
        // Cross-platform `--force` contract: the user-supplied target
        // already exists, --force was passed, and composer.json has
        // inline keys. After init: composer.json stripped, the
        // pre-existing file at $target is overwritten by the migrated
        // content. POSIX `rename()` would do this implicitly; Windows
        // would otherwise refuse, leaving the new content stranded
        // at the canonical path.
        \mkdir($this->tmp . '/config', 0o777, true);
        \file_put_contents($this->tmp . '/config/skills.json', '{"target":"stale"}');

        $this->writeComposerJson([
            'extra' => ['skills' => ['target' => 'fresh/skills']],
        ]);

        $io = new BufferIO();
        $code = (new InitRunner())->run(
            Path::create($this->tmp),
            $io,
            new InitOptions(path: 'config/skills.json', force: true),
        );

        Assert::same(
            $code,
            Command::SUCCESS,
            'force-overwrite must succeed; got stderr: ' . $io->getOutput(),
        );

        // The pre-existing stale file is gone; the migrated content
        // lives at the requested non-default path.
        $skills = \json_decode(
            (string) \file_get_contents($this->tmp . '/config/skills.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        Assert::same($skills['target'] ?? null, 'fresh/skills', 'target must reflect migrated value');

        // Canonical location is empty (the rename moved the file out).
        Assert::false(
            \is_file($this->tmp . '/skills.json'),
            'rename must move the canonical file to the user-supplied path',
        );
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

    public function forceInteractiveFoldsLegacyTrustKeysAndRoundTripsThem(): void
    {
        // Regression guard: the interactive `--force` path reads the
        // existing skills.json raw (no file migrators) so the wizard can
        // show current values as defaults. A legacy flat `trusted` /
        // `trusted-replace` / `local` file must still surface those as
        // trust defaults; accepting every prompt verbatim must round-trip
        // the config into the `dependencies` form without dropping the
        // patterns or flipping `trusted-replace` back off.
        \file_put_contents(
            $this->tmp . '/skills.json',
            (string) \json_encode(
                [
                    '$schema' => InitRunner::SCHEMA_URL,
                    'target' => 'custom/skills',
                    'trusted' => ['acme/*'],
                    'trusted-replace' => true,
                    'local' => ['composer' => true],
                ],
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            ) . "\n",
        );

        // `setUserInputs` flips BufferIO into interactive mode, which is
        // what routes the runner through the wizard. Empty answers accept
        // each surfaced default; the final `yes` confirms the write.
        $io = new BufferIO();
        $io->setUserInputs([
            '',    // target → keep custom/skills
            '',    // aliases → keep none
            '',    // trusted → keep acme/*
            '',    // trusted-replace → keep true
            '',    // discovery → keep default
            '',    // auto-sync → keep default
            'yes', // confirm write
        ]);

        $code = (new InitRunner())->run(
            Path::create($this->tmp),
            $io,
            new InitOptions(force: true),
        );

        Assert::same($code, Command::SUCCESS, 'stderr: ' . $io->getOutput());

        $written = $this->readSkillsJson();
        Assert::same($written['target'] ?? null, 'custom/skills');
        // The flat legacy keys are gone; trust lands under the canonical
        // `dependencies.composer` block with both the patterns and the
        // replace flag intact. `local.composer: true` is the composer
        // default, so the wizard omits the redundant `enabled` toggle.
        Assert::false(\array_key_exists('trusted', $written), 'flat trusted must not survive the rewrite');
        Assert::false(
            \array_key_exists('trusted-replace', $written),
            'flat trusted-replace must not survive the rewrite',
        );
        Assert::false(\array_key_exists('local', $written), 'flat local must not survive the rewrite');
        Assert::same(
            $written['dependencies'] ?? null,
            ['composer' => ['trusted' => ['acme/*'], 'trusted-replace' => true]],
            'trust must round-trip into the dependencies form without loss',
        );
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
