<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config\Mapper;

use Composer\IO\BufferIO;
use Internal\Path;
use LLM\Skills\Config\Mapper\MigrationStatus;
use LLM\Skills\Config\Mapper\ProjectConfigMigrator;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Unit coverage for the auto-migration body. Acceptance counterparts
 * exercise the same logic through `composer skills:update`; this
 * suite isolates the decision tree (when to migrate vs skip vs fail)
 * and the filesystem effects so failures point at the migrator
 * rather than at upstream plumbing.
 */
#[Test]
final class ProjectConfigMigratorTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-migrate-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function skipsWhenSkillsJsonAlreadyExists(): void
    {
        // Existing skills.json is the user's source of truth — never
        // overwrite it via auto-migration. Inline keys, if present in
        // composer.json, are simply ignored.
        \file_put_contents($this->tmp . '/skills.json', '{"$schema":"foo"}');
        $this->writeComposerJson([
            'extra' => ['skills' => ['target' => 'noop']],
        ]);

        $result = (new ProjectConfigMigrator())->migrate(
            Path::create($this->tmp),
            new BufferIO(),
        );

        Assert::same($result->status, MigrationStatus::Skipped);
        Assert::same($result->migratedKeys, []);
        Assert::same(
            (string) \file_get_contents($this->tmp . '/skills.json'),
            '{"$schema":"foo"}',
            'existing skills.json must not be touched',
        );
    }

    public function skipsWhenComposerJsonIsAbsent(): void
    {
        // Standalone mode: no composer.json means no inline to
        // migrate from. The migrator is a no-op — it never
        // proactively creates a stub.
        $result = (new ProjectConfigMigrator())->migrate(
            Path::create($this->tmp),
            new BufferIO(),
        );

        Assert::same($result->status, MigrationStatus::Skipped);
        Assert::false(\is_file($this->tmp . '/skills.json'));
    }

    public function skipsWhenInlineHasNoProjectKeys(): void
    {
        // Donor-side `source` is not a project key — the migrator
        // must not consider it migratable. Without any project key,
        // there's nothing to relocate.
        $this->writeComposerJson([
            'extra' => ['skills' => ['source' => 'resources/skills']],
        ]);

        $result = (new ProjectConfigMigrator())->migrate(
            Path::create($this->tmp),
            new BufferIO(),
        );

        Assert::same($result->status, MigrationStatus::Skipped);
        Assert::false(
            \is_file($this->tmp . '/skills.json'),
            'no skills.json must be created when there are no inline project keys',
        );
        // composer.json's `source` key stays put.
        $composer = $this->readComposerJson();
        Assert::same($composer['extra']['skills']['source'] ?? null, 'resources/skills');
    }

    public function migratesProjectKeysAndStripsThemFromComposerJson(): void
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

        $result = (new ProjectConfigMigrator())->migrate(
            Path::create($this->tmp),
            new BufferIO(),
        );

        Assert::same($result->status, MigrationStatus::Migrated);
        Assert::same($result->migratedKeys, ['target', 'trusted', 'auto-sync']);

        // The new skills.json carries the migrated keys plus `$schema`.
        $skills = $this->readSkillsJson();
        Assert::same($skills['target'] ?? null, 'custom/skills');
        Assert::same($skills['trusted'] ?? null, ['acme/*']);
        Assert::same($skills['auto-sync'] ?? null, true);
        Assert::true(\array_key_exists('$schema', $skills));

        // composer.json no longer contains the migrated keys.
        $composer = $this->readComposerJson();
        $remaining = $composer['extra']['skills'] ?? [];
        Assert::false(\array_key_exists('target', $remaining));
        Assert::false(\array_key_exists('trusted', $remaining));
        Assert::false(\array_key_exists('auto-sync', $remaining));
    }

    public function preservesDonorSourceDuringMigration(): void
    {
        // A package can be both donor and consumer. The donor-side
        // `source` key must stay in composer.json regardless of how
        // many project keys move out.
        $this->writeComposerJson([
            'name' => 'demo/dual',
            'extra' => [
                'skills' => [
                    'source' => 'resources/skills',
                    'target' => 'custom/skills',
                ],
            ],
        ]);

        $result = (new ProjectConfigMigrator())->migrate(
            Path::create($this->tmp),
            new BufferIO(),
        );

        Assert::same($result->status, MigrationStatus::Migrated);

        // donor `source` still in composer.json
        $composer = $this->readComposerJson();
        Assert::same($composer['extra']['skills']['source'] ?? null, 'resources/skills');
        // and NOT in skills.json
        $skills = $this->readSkillsJson();
        Assert::false(\array_key_exists('source', $skills));
    }

    public function refusesToMigrateMalformedInline(): void
    {
        // Pre-flight catches malformed inline before any write —
        // migrating the bug into skills.json would just relocate the
        // problem.
        $this->writeComposerJson([
            'extra' => ['skills' => ['auto-sync' => 'yes']],
        ]);

        $io = new BufferIO();
        $result = (new ProjectConfigMigrator())->migrate(
            Path::create($this->tmp),
            $io,
        );

        Assert::same($result->status, MigrationStatus::Failed);
        Assert::false(
            \is_file($this->tmp . '/skills.json'),
            'no skills.json must be written when inline is malformed',
        );
        // composer.json must be untouched too — the migrator never
        // half-applies a failed migration.
        $composer = $this->readComposerJson();
        Assert::same($composer['extra']['skills']['auto-sync'] ?? null, 'yes');
        Assert::true(\str_contains($io->getOutput(), 'cannot auto-migrate'));
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

    /**
     * @return array<string, mixed>
     */
    private function readComposerJson(): array
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
}
