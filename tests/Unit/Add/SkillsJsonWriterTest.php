<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Add;

use Internal\Path;
use LLM\Skills\Add\SkillsJsonWriter;
use LLM\Skills\Config\Mapper\ProjectConfigMigrator;
use LLM\Skills\Config\RemoteEntry;
use LLM\Skills\Discovery\Provider\ProviderId;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Verifies the {@see SkillsJsonWriter} upsert + stable-sort contract.
 *
 * The fixture writes raw JSON files in a tmp dir (no Composer plumbing
 * needed) and re-reads them after the writer runs to inspect the
 * resulting shape.
 */
#[Test]
#[Covers(SkillsJsonWriter::class)]
final class SkillsJsonWriterTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-writer-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function createsFreshFileWithSchemaPointer(): void
    {
        // No skills.json yet → the writer creates one with the
        // canonical `$schema` URL so editors get autocompletion out
        // of the box.
        (new SkillsJsonWriter())->upsertRemote(
            Path::create($this->tmp),
            self::entry('acme/skills', ref: '^1.0'),
        );

        $payload = $this->readSkillsJson();
        Assert::same($payload['$schema'] ?? null, ProjectConfigMigrator::SCHEMA_URL);
        Assert::count((array) $payload['remote'], 1);
    }

    public function preservesUnrelatedTopLevelKeys(): void
    {
        // The writer must NOT clobber `target` / `aliases` / `trusted`
        // — only `remote[]` is its concern.
        $this->writeSkillsJson([
            'target' => '.agents/skills',
            'aliases' => ['.claude/skills'],
            'trusted' => ['acme/*'],
        ]);

        (new SkillsJsonWriter())->upsertRemote(
            Path::create($this->tmp),
            self::entry('acme/skills'),
        );

        $payload = $this->readSkillsJson();
        Assert::same($payload['target'] ?? null, '.agents/skills');
        Assert::same($payload['aliases'] ?? null, ['.claude/skills']);
        Assert::same($payload['trusted'] ?? null, ['acme/*']);
    }

    public function upsertReplacesEntryWithSameCompositeKey(): void
    {
        // Spec §3.4 + §6.1: same (from, host, package) ⇒ overwrite
        // in place, not append. The new entry's optional fields
        // (ref, extras) supersede the old.
        $this->writeSkillsJson([
            'remote' => [
                ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v1.0.0'],
            ],
        ]);

        (new SkillsJsonWriter())->upsertRemote(
            Path::create($this->tmp),
            self::entry('acme/skills', ref: '^2.0.0'),
        );

        $payload = $this->readSkillsJson();
        $remote = (array) $payload['remote'];
        Assert::count($remote, 1);
        Assert::same($remote[0]['ref'] ?? null, '^2.0.0');
    }

    public function upsertDistinguishesByHost(): void
    {
        // Same package on github.com vs corp GHE are distinct
        // entries — composite key includes host.
        $this->writeSkillsJson([
            'remote' => [
                ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v1.0.0'],
            ],
        ]);

        (new SkillsJsonWriter())->upsertRemote(
            Path::create($this->tmp),
            self::entry('acme/skills', host: 'https://github.corp.example.com', ref: 'v1.0.0'),
        );

        $payload = $this->readSkillsJson();
        $remote = (array) $payload['remote'];
        Assert::count($remote, 2);
    }

    public function appendsWhenCompositeKeyIsNew(): void
    {
        $this->writeSkillsJson([
            'remote' => [
                ['from' => 'github', 'package' => 'acme/x'],
            ],
        ]);

        (new SkillsJsonWriter())->upsertRemote(
            Path::create($this->tmp),
            self::entry('beta/y'),
        );

        $payload = $this->readSkillsJson();
        $remote = (array) $payload['remote'];
        Assert::count($remote, 2);
    }

    public function stableSortIsAppliedAfterUpsert(): void
    {
        // Composite key sort order: ascii ordering of `from|host|id`.
        // Reverse-input order should come out sorted.
        $this->writeSkillsJson([
            'remote' => [
                ['from' => 'github', 'package' => 'zeta/last'],
                ['from' => 'github', 'package' => 'beta/middle'],
            ],
        ]);

        (new SkillsJsonWriter())->upsertRemote(
            Path::create($this->tmp),
            self::entry('alpha/first'),
        );

        $payload = $this->readSkillsJson();
        $packages = \array_map(static fn(array $e) => $e['package'], (array) $payload['remote']);
        Assert::same($packages, ['alpha/first', 'beta/middle', 'zeta/last']);
    }

    public function writtenEntryHasFixedKeyOrder(): void
    {
        // Spec §3.5: from → host → package → ref → extras.
        (new SkillsJsonWriter())->upsertRemote(
            Path::create($this->tmp),
            new RemoteEntry(
                from: 'github',
                package: 'acme/skills',
                url: null,
                host: 'https://github.com',
                ref: 'v1.0.0',
                extras: ['custom' => 'extra'],
            ),
        );

        $raw = (string) \file_get_contents($this->tmp . '/skills.json');

        // Snapshot the order of keys inside the remote entry. We
        // can't trust unordered JSON associative-array comparison —
        // the file order is what diffs and users see.
        $fromPos = \strpos($raw, '"from"');
        $hostPos = \strpos($raw, '"host"');
        $packagePos = \strpos($raw, '"package"');
        $refPos = \strpos($raw, '"ref"');
        $customPos = \strpos($raw, '"custom"');

        Assert::true($fromPos !== false && $hostPos !== false);
        Assert::true($fromPos < $hostPos);
        Assert::true(\is_int($hostPos) && \is_int($packagePos) && $hostPos < $packagePos);
        Assert::true(\is_int($packagePos) && \is_int($refPos) && $packagePos < $refPos);
        Assert::true(\is_int($refPos) && \is_int($customPos) && $refPos < $customPos);
    }

    public function atomicWriteLeavesNoTempFileBehindOnSuccess(): void
    {
        // The temp file pattern is `skills.json.<hex>.tmp`. After a
        // successful write, only the canonical name should remain.
        (new SkillsJsonWriter())->upsertRemote(
            Path::create($this->tmp),
            self::entry('acme/skills'),
        );

        $entries = (array) \scandir($this->tmp);
        $names = \array_values(\array_filter(
            $entries,
            static fn($e) => $e !== '.' && $e !== '..',
        ));
        Assert::same($names, ['skills.json']);
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
    private function writeSkillsJson(array $data): void
    {
        \file_put_contents(
            $this->tmp . '/skills.json',
            \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n",
        );
    }

    /**
     * @param non-empty-string $package
     * @param non-empty-string|null $host
     * @param non-empty-string|null $ref
     */
    private static function entry(string $package, ?string $host = null, ?string $ref = null): RemoteEntry
    {
        return new RemoteEntry(
            from: ProviderId::GITHUB,
            package: $package,
            url: null,
            host: $host,
            ref: $ref,
        );
    }
}
