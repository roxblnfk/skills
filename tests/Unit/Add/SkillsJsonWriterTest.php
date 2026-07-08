<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Add;

use Internal\Path;
use LLM\Skills\Add\SkillsJsonWriter;
use LLM\Skills\Config\Mapper\ProjectConfigMigrator;
use LLM\Skills\Config\SourceEntry;
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
        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::entry('acme/skills', ref: '^1.0'),
        );

        $payload = $this->readSkillsJson();
        Assert::same($payload['$schema'] ?? null, ProjectConfigMigrator::SCHEMA_URL);
        Assert::count((array) $payload['sources'], 1);
    }

    public function consecutiveUpsertsOverwriteExistingFile(): void
    {
        // The atomic-write path renames a temp file into skills.json.
        // On Windows, rename() refuses to overwrite an existing
        // destination, so a second upsert into an already-existing
        // file must still succeed (the writer falls back to
        // unlink+rename). Reproduces the failure mode that a second
        // `skills:add` would hit on Windows otherwise.
        $writer = new SkillsJsonWriter();
        $root = Path::create($this->tmp);

        $writer->upsertSource($root, self::entry('acme/skills', ref: 'v1.0.0'));
        $writer->upsertSource($root, self::entry('acme/skills', ref: 'v2.0.0'));
        $writer->upsertSource($root, self::entry('acme/other', ref: 'v1.0.0'));

        $payload = $this->readSkillsJson();
        /** @var list<array<string, mixed>> $sources */
        $sources = (array) $payload['sources'];
        Assert::count($sources, 2);
        $skills = \array_values(\array_filter(
            $sources,
            static fn(array $e): bool => ($e['package'] ?? null) === 'acme/skills',
        ));
        Assert::count($skills, 1);
        Assert::same($skills[0]['ref'] ?? null, 'v2.0.0');
    }

    public function preservesUnrelatedTopLevelKeys(): void
    {
        // The writer must NOT clobber unrelated keys like `target` /
        // `aliases` — only `sources[]` is its concern (the legacy trust
        // trio is the one exception; it is normalised into `dependencies`,
        // covered by its own test).
        $this->writeSkillsJson([
            'target' => '.agents/skills',
            'aliases' => ['.claude/skills'],
        ]);

        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::entry('acme/skills'),
        );

        $payload = $this->readSkillsJson();
        Assert::same($payload['target'] ?? null, '.agents/skills');
        Assert::same($payload['aliases'] ?? null, ['.claude/skills']);
    }

    public function upsertReplacesEntryWithSameCompositeKey(): void
    {
        // Same (from, host, package) ⇒ overwrite in place, not append.
        // The new entry's optional fields (ref, extras) supersede the old.
        $this->writeSkillsJson([
            'sources' => [
                ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v1.0.0'],
            ],
        ]);

        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::entry('acme/skills', ref: '^2.0.0'),
        );

        $payload = $this->readSkillsJson();
        $sources = (array) $payload['sources'];
        Assert::count($sources, 1);
        Assert::same($sources[0]['ref'] ?? null, '^2.0.0');
    }

    public function upsertDistinguishesByHost(): void
    {
        // Same package on github.com vs corp GHE are distinct
        // entries — composite key includes host.
        $this->writeSkillsJson([
            'sources' => [
                ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v1.0.0'],
            ],
        ]);

        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::entry('acme/skills', host: 'https://github.corp.example.com', ref: 'v1.0.0'),
        );

        $payload = $this->readSkillsJson();
        $sources = (array) $payload['sources'];
        Assert::count($sources, 2);
    }

    public function appendsWhenCompositeKeyIsNew(): void
    {
        $this->writeSkillsJson([
            'sources' => [
                ['from' => 'github', 'package' => 'acme/x'],
            ],
        ]);

        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::entry('beta/y'),
        );

        $payload = $this->readSkillsJson();
        $sources = (array) $payload['sources'];
        Assert::count($sources, 2);
    }

    public function stableSortIsAppliedAfterUpsert(): void
    {
        // Composite key sort order: ascii ordering of `from|host|id`.
        // Reverse-input order should come out sorted.
        $this->writeSkillsJson([
            'sources' => [
                ['from' => 'github', 'package' => 'zeta/last'],
                ['from' => 'github', 'package' => 'beta/middle'],
            ],
        ]);

        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::entry('alpha/first'),
        );

        $payload = $this->readSkillsJson();
        $packages = \array_map(static fn(array $e) => $e['package'], (array) $payload['sources']);
        Assert::same($packages, ['alpha/first', 'beta/middle', 'zeta/last']);
    }

    public function writtenEntryHasFixedKeyOrder(): void
    {
        // Fixed key order: from → host → package → ref → extras.
        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            new SourceEntry(
                from: 'github',
                package: 'acme/skills',
                url: null,
                host: 'https://github.com',
                ref: 'v1.0.0',
                extras: ['custom' => 'extra'],
            ),
        );

        $raw = (string) \file_get_contents($this->tmp . '/skills.json');

        // Snapshot the order of keys inside the source entry. We
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

    public function upsertCollapsesPreExistingDuplicatesIntoOne(): void
    {
        // A hand-edited skills.json can have two entries with the same
        // composite key. The mapper rejects that on load, but the
        // writer also operates on raw-loaded payloads — so the upsert
        // must collapse duplicates into a single normalised entry,
        // not insert a copy next to each.
        $this->writeSkillsJson([
            'sources' => [
                ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v0.9.0'],
                ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v0.9.1'],
                ['from' => 'github', 'package' => 'acme/other', 'ref' => 'v2.0.0'],
            ],
        ]);

        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::entry('acme/skills', ref: 'v1.2.3'),
        );

        $payload = $this->readSkillsJson();
        /** @var list<array<string, mixed>> $sources */
        $sources = (array) $payload['sources'];

        Assert::count($sources, 2);

        $acmeSkills = \array_values(\array_filter(
            $sources,
            static fn(array $e): bool => ($e['package'] ?? null) === 'acme/skills',
        ));
        Assert::count($acmeSkills, 1);
        Assert::same($acmeSkills[0]['ref'] ?? null, 'v1.2.3');
    }

    public function freshEntryStoresSkillsAllowlistVerbatim(): void
    {
        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::entry('acme/skills', ref: 'v1.0.0', skills: ['code-review', 'refactor']),
        );

        $payload = $this->readSkillsJson();
        /** @var list<array<string, mixed>> $sources */
        $sources = (array) $payload['sources'];
        Assert::count($sources, 1);
        Assert::same($sources[0]['skills'] ?? null, ['code-review', 'refactor']);
    }

    public function freshEntryOmitsSkillsKeyWhenAllowlistIsNull(): void
    {
        // Skipping the field is meaningfully different from `"skills": []`:
        // it means "sync every skill" and must produce a clean entry
        // without an empty array forcing the user to delete it.
        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::entry('acme/skills', ref: 'v1.0.0'),
        );

        $payload = $this->readSkillsJson();
        /** @var list<array<string, mixed>> $sources */
        $sources = (array) $payload['sources'];
        Assert::false(\array_key_exists('skills', $sources[0]));
    }

    public function consecutiveUpsertsMergeSkillsAllowlists(): void
    {
        // Repeated `skills:add` calls additively grow the allowlist.
        // Order is preserved: pre-existing names first, then the new
        // ones in the order the user typed them. Duplicates collapse.
        $writer = new SkillsJsonWriter();
        $root = Path::create($this->tmp);

        $writer->upsertSource($root, self::entry('acme/skills', skills: ['alpha', 'beta']));
        $writer->upsertSource($root, self::entry('acme/skills', skills: ['beta', 'gamma']));

        $payload = $this->readSkillsJson();
        /** @var list<array<string, mixed>> $sources */
        $sources = (array) $payload['sources'];
        Assert::count($sources, 1);
        Assert::same($sources[0]['skills'] ?? null, ['alpha', 'beta', 'gamma']);
    }

    public function freshEntryRoundtripsEmptySkillsList(): void
    {
        // An empty allowlist is a meaningful state ("donor on file, no
        // skills pulled"). Distinct from omitting the field. The writer
        // must emit `"skills": []` verbatim.
        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::entry('acme/skills', ref: 'v1.0.0', skills: []),
        );

        $payload = $this->readSkillsJson();
        /** @var list<array<string, mixed>> $sources */
        $sources = (array) $payload['sources'];
        Assert::true(\array_key_exists('skills', $sources[0]));
        Assert::same($sources[0]['skills'], []);
    }

    public function upsertWithoutSkillsPreservesEmptyAllowlist(): void
    {
        // The "donor disabled but on file" state must survive a
        // follow-up `skills:add` that does not touch the allowlist —
        // otherwise running `skills:add ... --ref=v2` on a temporarily
        // disabled entry would silently re-enable every skill.
        $this->writeSkillsJson([
            'sources' => [
                ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v1.0.0', 'skills' => []],
            ],
        ]);

        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::entry('acme/skills', ref: 'v2.0.0'),
        );

        $payload = $this->readSkillsJson();
        /** @var list<array<string, mixed>> $sources */
        $sources = (array) $payload['sources'];
        Assert::same($sources[0]['skills'] ?? null, []);
        Assert::same($sources[0]['ref'] ?? null, 'v2.0.0');
    }

    public function upsertWithoutSkillsPreservesExistingAllowlist(): void
    {
        // A subsequent `skills:add` without --skill must NOT wipe the
        // previously stored allowlist — that would be a destructive
        // surprise. To clear the field the user has to edit JSON by
        // hand (intentional, narrow surface for that case).
        $writer = new SkillsJsonWriter();
        $root = Path::create($this->tmp);

        $writer->upsertSource($root, self::entry('acme/skills', skills: ['alpha']));
        $writer->upsertSource($root, self::entry('acme/skills', ref: 'v2.0.0'));

        $payload = $this->readSkillsJson();
        /** @var list<array<string, mixed>> $sources */
        $sources = (array) $payload['sources'];
        Assert::count($sources, 1);
        Assert::same($sources[0]['skills'] ?? null, ['alpha']);
        Assert::same($sources[0]['ref'] ?? null, 'v2.0.0');
    }

    public function skillsFieldIsEmittedAfterRefInTheCanonicalKeyOrder(): void
    {
        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::entry('acme/skills', ref: 'v1.0.0', skills: ['hello']),
        );

        $raw = (string) \file_get_contents($this->tmp . '/skills.json');
        $refPos = \strpos($raw, '"ref"');
        $skillsPos = \strpos($raw, '"skills"');
        Assert::true(\is_int($refPos) && \is_int($skillsPos), 'ref and skills must both be present');
        Assert::true($refPos < $skillsPos, 'skills must follow ref in canonical key order');
    }

    public function atomicWriteLeavesNoTempFileBehindOnSuccess(): void
    {
        // The temp file pattern is `skills.json.<hex>.tmp`. After a
        // successful write, only the canonical name should remain.
        (new SkillsJsonWriter())->upsertSource(
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

    public function upsertIntoLegacyRemoteFileProducesSourcesOnlyFile(): void
    {
        // A file still on the deprecated `remote` key is normalised on
        // write: its entries move under `sources`, the old key is
        // dropped, unrelated keys survive, and the additive
        // allowlist-merge behaviour is preserved across the rename.
        $this->writeSkillsJson([
            'target' => '.agents/skills',
            'remote' => [
                ['from' => 'github', 'package' => 'acme/skills', 'ref' => 'v1.0.0', 'skills' => ['alpha']],
            ],
        ]);

        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::entry('acme/skills', ref: 'v2.0.0', skills: ['beta']),
        );

        $payload = $this->readSkillsJson();
        Assert::false(\array_key_exists('remote', $payload), 'deprecated key must be dropped from the output');
        Assert::true(\array_key_exists('sources', $payload));
        Assert::same($payload['target'] ?? null, '.agents/skills', 'unrelated keys must survive');

        /** @var list<array<string, mixed>> $sources */
        $sources = (array) $payload['sources'];
        Assert::count($sources, 1);
        Assert::same($sources[0]['ref'] ?? null, 'v2.0.0');
        Assert::same(
            $sources[0]['skills'] ?? null,
            ['alpha', 'beta'],
            'the legacy entry allowlist must merge with the new one',
        );
    }

    public function upsertNormalisesLegacyTrustTrioIntoDependencies(): void
    {
        // A file still on the legacy trust trio is normalised on write:
        // `local` / `trusted` / `trusted-replace` fold into a
        // `dependencies` block, the legacy keys are dropped, and
        // unrelated keys survive.
        $this->writeSkillsJson([
            'target' => '.agents/skills',
            'trusted' => ['acme/*'],
            'trusted-replace' => false,
            'local' => ['composer' => true, 'npm' => false],
        ]);

        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::entry('acme/skills'),
        );

        $payload = $this->readSkillsJson();
        Assert::false(\array_key_exists('trusted', $payload), 'legacy trusted must be dropped');
        Assert::false(\array_key_exists('trusted-replace', $payload), 'legacy trusted-replace must be dropped');
        Assert::false(\array_key_exists('local', $payload), 'legacy local must be dropped');
        Assert::same($payload['target'] ?? null, '.agents/skills', 'unrelated keys must survive');
        Assert::same($payload['dependencies'] ?? null, [
            'composer' => ['enabled' => true, 'trusted' => ['acme/*'], 'trusted-replace' => false],
            'npm' => false,
        ]);
        Assert::count((array) $payload['sources'], 1);
    }

    public function dirEntryStoresPathAsIdentifier(): void
    {
        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::dirEntry('./skills'),
        );

        $payload = $this->readSkillsJson();
        /** @var list<array<string, mixed>> $sources */
        $sources = (array) $payload['sources'];
        Assert::count($sources, 1);
        Assert::same($sources[0]['from'] ?? null, 'dir');
        Assert::same($sources[0]['path'] ?? null, './skills');
        Assert::false(\array_key_exists('package', $sources[0]));
        Assert::false(\array_key_exists('url', $sources[0]));
    }

    public function dirEntryUpsertsByPathCompositeKey(): void
    {
        // Same `path` ⇒ same composite key (`dir||./skills`) ⇒ overwrite
        // in place, not append. Here the second add adds a package
        // override to the same path.
        $this->writeSkillsJson([
            'sources' => [
                ['from' => 'dir', 'path' => './skills'],
            ],
        ]);

        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::dirEntry('./skills', package: 'myorg/shared'),
        );

        $payload = $this->readSkillsJson();
        /** @var list<array<string, mixed>> $sources */
        $sources = (array) $payload['sources'];
        Assert::count($sources, 1);
        Assert::same($sources[0]['path'] ?? null, './skills');
        Assert::same($sources[0]['package'] ?? null, 'myorg/shared');
    }

    public function dirDifferentPathsAppend(): void
    {
        $this->writeSkillsJson([
            'sources' => [
                ['from' => 'dir', 'path' => './skills'],
            ],
        ]);

        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::dirEntry('../shared-skills'),
        );

        $payload = $this->readSkillsJson();
        Assert::count((array) $payload['sources'], 2);
    }

    public function dirEntryEmitsPathInTheIdentifierSlot(): void
    {
        // Key order for a dir entry: from → path → package (override).
        (new SkillsJsonWriter())->upsertSource(
            Path::create($this->tmp),
            self::dirEntry('./skills', package: 'myorg/shared'),
        );

        $raw = (string) \file_get_contents($this->tmp . '/skills.json');
        $fromPos = \strpos($raw, '"from"');
        $pathPos = \strpos($raw, '"path"');
        $packagePos = \strpos($raw, '"package"');

        Assert::true(\is_int($fromPos) && \is_int($pathPos) && $fromPos < $pathPos);
        Assert::true(\is_int($pathPos) && \is_int($packagePos) && $pathPos < $packagePos);
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
     * @param list<non-empty-string>|null $skills
     */
    private static function entry(
        string $package,
        ?string $host = null,
        ?string $ref = null,
        ?array $skills = null,
    ): SourceEntry {
        return new SourceEntry(
            from: ProviderId::GITHUB,
            package: $package,
            url: null,
            host: $host,
            ref: $ref,
            skills: $skills,
        );
    }

    /**
     * @param non-empty-string $path
     * @param non-empty-string|null $package
     * @param list<non-empty-string>|null $skills
     */
    private static function dirEntry(
        string $path,
        ?string $package = null,
        ?array $skills = null,
    ): SourceEntry {
        return new SourceEntry(
            from: ProviderId::DIR,
            package: $package,
            url: null,
            host: null,
            ref: null,
            skills: $skills,
            path: $path,
        );
    }
}
