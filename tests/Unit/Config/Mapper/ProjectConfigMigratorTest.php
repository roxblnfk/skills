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

    public function collapsesEmptyExtraSkillsAndExtraAfterMigration(): void
    {
        // The composer.json carries only project keys under
        // extra.skills (no donor `source`, no unrelated entries).
        // After migration the `"skills": {}` leftover and the
        // now-empty `"extra": {}` are both stripped — composer.json
        // should not accumulate dead nesting.
        $this->writeComposerJson([
            'name' => 'demo/clean',
            'extra' => [
                'skills' => [
                    'target' => 'custom/skills',
                    'auto-sync' => false,
                ],
            ],
        ]);

        $result = (new ProjectConfigMigrator())->migrate(
            Path::create($this->tmp),
            new BufferIO(),
        );

        Assert::same($result->status, MigrationStatus::Migrated);

        $composer = $this->readComposerJson();
        Assert::false(
            \array_key_exists('extra', $composer),
            'empty extra must be removed entirely; composer.json keys: '
            . \implode(', ', \array_keys($composer)),
        );
    }

    public function keepsExtraSkillsWhenSourceRemains(): void
    {
        // Donor `source` lives alongside project keys. After migration
        // the project keys are gone but `extra.skills.source` keeps
        // the skills node alive — and therefore `extra` too.
        $this->writeComposerJson([
            'name' => 'demo/dual',
            'extra' => [
                'skills' => [
                    'source' => 'resources/skills',
                    'target' => 'custom/skills',
                ],
            ],
        ]);

        (new ProjectConfigMigrator())->migrate(
            Path::create($this->tmp),
            new BufferIO(),
        );

        $composer = $this->readComposerJson();
        Assert::same(
            $composer['extra']['skills']['source'] ?? null,
            'resources/skills',
            'donor `source` must keep extra.skills alive',
        );
    }

    public function keepsExtraWhenOtherExtraKeysExist(): void
    {
        // Even if extra.skills becomes empty, `extra` may carry other
        // top-level keys (composer plugin class declarations, scripts,
        // …). Those must stay.
        $this->writeComposerJson([
            'name' => 'demo/has-other-extras',
            'extra' => [
                'skills' => ['target' => 'custom/skills'],
                'branch-alias' => ['dev-main' => '1.x-dev'],
            ],
        ]);

        (new ProjectConfigMigrator())->migrate(
            Path::create($this->tmp),
            new BufferIO(),
        );

        $composer = $this->readComposerJson();
        Assert::true(
            \array_key_exists('extra', $composer),
            'extra must stay because branch-alias is still there',
        );
        Assert::false(
            \array_key_exists('skills', $composer['extra'] ?? []),
            'empty skills node must be removed',
        );
        Assert::true(
            \array_key_exists('branch-alias', $composer['extra'] ?? []),
            'unrelated extra keys must survive',
        );
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

    public function migratesRemoteInlineBlockUnderSourcesKey(): void
    {
        // Inline `extra.skills.remote` migrates into skills.json under
        // the canonical `sources` key — the written file never carries
        // the deprecated alias — and composer.json is stripped of the
        // key the user actually wrote (`remote`).
        $this->writeComposerJson([
            'name' => 'demo/consumer',
            'extra' => [
                'skills' => [
                    'target' => 'custom/skills',
                    'remote' => [
                        ['from' => 'github', 'package' => 'acme/skills'],
                    ],
                ],
            ],
        ]);

        $result = (new ProjectConfigMigrator())->migrate(
            Path::create($this->tmp),
            new BufferIO(),
        );

        Assert::same($result->status, MigrationStatus::Migrated);

        $skills = $this->readSkillsJson();
        Assert::false(\array_key_exists('remote', $skills), 'written file must not carry the deprecated key');
        Assert::same(
            $skills['sources'] ?? null,
            [['from' => 'github', 'package' => 'acme/skills']],
        );

        // composer.json had `remote` stripped (not `sources`).
        $composer = $this->readComposerJson();
        $remaining = $composer['extra']['skills'] ?? [];
        Assert::false(\array_key_exists('remote', $remaining));
        Assert::false(\array_key_exists('target', $remaining));
    }

    public function renamesRemoteKeyToSourcesInPlace(): void
    {
        // A skills.json still on the deprecated key is rewritten with
        // the key renamed, its slot and every sibling key preserved,
        // and a normal-verbosity notice emitted.
        $this->writeSkillsJson([
            '$schema' => 'https://example.com/skills.schema.json',
            'target' => 'custom/skills',
            'remote' => [
                ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v1.0.0'],
            ],
            'trusted' => ['acme/*'],
        ]);

        $io = new BufferIO();
        $result = (new ProjectConfigMigrator())->renameSourcesKey(
            Path::create($this->tmp),
            $io,
        );

        Assert::same($result->status, MigrationStatus::Migrated);

        $skills = $this->readSkillsJson();
        Assert::false(\array_key_exists('remote', $skills), 'deprecated key must be gone');
        Assert::same(
            $skills['sources'] ?? null,
            [['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v1.0.0']],
        );
        // Position preserved: `sources` sits exactly where `remote` was,
        // and every unrelated key stays put in order.
        Assert::same(
            \array_keys($skills),
            ['$schema', 'target', 'sources', 'trusted'],
            'the renamed key must keep its slot and all siblings must be preserved',
        );
        Assert::same($skills['target'] ?? null, 'custom/skills');
        Assert::same($skills['trusted'] ?? null, ['acme/*']);

        Assert::true(
            \str_contains($io->getOutput(), 'renamed "remote" to "sources" in skills.json'),
            'a [migrate] notice must be emitted; got: ' . $io->getOutput(),
        );
    }

    public function renameIsNoOpWhenFileAlreadyUsesSources(): void
    {
        $this->writeSkillsJson([
            'sources' => [['from' => 'github', 'package' => 'acme/skills']],
        ]);
        $before = (string) \file_get_contents($this->tmp . '/skills.json');

        $io = new BufferIO();
        $result = (new ProjectConfigMigrator())->renameSourcesKey(
            Path::create($this->tmp),
            $io,
        );

        Assert::same($result->status, MigrationStatus::Skipped);
        Assert::same(
            (string) \file_get_contents($this->tmp . '/skills.json'),
            $before,
            'a file already on sources must not be rewritten',
        );
        Assert::false(\str_contains($io->getOutput(), 'renamed'));
    }

    public function renameIsNoOpWhenFileAbsent(): void
    {
        $result = (new ProjectConfigMigrator())->renameSourcesKey(
            Path::create($this->tmp),
            new BufferIO(),
        );

        Assert::same($result->status, MigrationStatus::Skipped);
        Assert::false(\is_file($this->tmp . '/skills.json'));
    }

    public function renameLeavesFileWithBothKeysUntouched(): void
    {
        // A file carrying both keys is rejected by the mapper's fatal
        // "both present" check; the migrator must not guess which list
        // wins, so it leaves the bytes exactly as they are.
        $this->writeSkillsJson([
            'sources' => [['from' => 'github', 'package' => 'acme/keep']],
            'remote' => [['from' => 'github', 'package' => 'acme/drop']],
        ]);
        $before = (string) \file_get_contents($this->tmp . '/skills.json');

        $result = (new ProjectConfigMigrator())->renameSourcesKey(
            Path::create($this->tmp),
            new BufferIO(),
        );

        Assert::same($result->status, MigrationStatus::Skipped);
        Assert::same(
            (string) \file_get_contents($this->tmp . '/skills.json'),
            $before,
            'a both-keys file must be left byte-for-byte untouched',
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeSkillsJson(array $data): void
    {
        \file_put_contents(
            $this->tmp . '/skills.json',
            \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n",
        );
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
