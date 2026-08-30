<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config\Mapper;

use Internal\Path;
use LLM\Skills\Config\Exception\MalformedVendorConfig;
use LLM\Skills\Config\Mapper\VendorConfigMapper;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class VendorConfigMapperTest
{
    public function declaresSkillsTrueWhenSourceKeyPresent(): void
    {
        Assert::true(VendorConfigMapper::declaresSkills(['skills' => ['source' => 'a']]));
    }

    public function declaresSkillsTrueWhenSourceKeyPresentEvenIfValueIsInvalid(): void
    {
        // A package that opted in by setting `source` but botched the value is
        // still a donor — surface a malformed warning rather than silently
        // skipping it.
        Assert::true(VendorConfigMapper::declaresSkills(['skills' => ['source' => '']]));
        Assert::true(VendorConfigMapper::declaresSkills(['skills' => ['source' => null]]));
        Assert::true(VendorConfigMapper::declaresSkills(['skills' => ['source' => 42]]));
    }

    public function declaresSkillsFalseWhenSkillsBlockHasNoSourceKey(): void
    {
        // A package that uses `llm/skills` for its own root-level config
        // (aliases, auto-sync, …) without donating skills of its own must not
        // be flagged as a malformed donor.
        Assert::false(VendorConfigMapper::declaresSkills([
            'skills' => ['aliases' => ['.claude/skills'], 'auto-sync' => true],
        ]));
        Assert::false(VendorConfigMapper::declaresSkills(['skills' => []]));
    }

    public function declaresSkillsFalseWhenSkillsBlockIsNotAnArray(): void
    {
        Assert::false(VendorConfigMapper::declaresSkills(['skills' => 'not-an-object']));
    }

    public function declaresSkillsFalseWhenSkillsKeyMissing(): void
    {
        Assert::false(VendorConfigMapper::declaresSkills(['other' => 'thing']));
    }

    public function declaresSkillsFalseForNonArrayExtra(): void
    {
        Assert::false(VendorConfigMapper::declaresSkills(null));
        Assert::false(VendorConfigMapper::declaresSkills('string'));
        Assert::false(VendorConfigMapper::declaresSkills(42));
    }

    public function fromExtraMapsHappyPath(): void
    {
        $mapper = new VendorConfigMapper();
        $root = Path::create(__DIR__);

        $donors = $mapper->fromExtra('acme/skills-pro', $root, [
            'skills' => ['source' => 'resources/skills'],
        ]);

        Assert::count($donors, 1);
        Assert::same($donors[0]->packageName, 'acme/skills-pro');
        Assert::same($donors[0]->source, 'resources/skills');
        Assert::same((string) $donors[0]->packageRoot, (string) $root);
    }

    public function fromExtraMapsSourceListToOneDonorRowPerEntry(): void
    {
        // A monorepo published as one package may ship skills in several
        // non-contiguous directories; each list entry becomes its own donor
        // row so downstream stays single-source.
        $mapper = new VendorConfigMapper();
        $root = Path::create(__DIR__);

        $donors = $mapper->fromExtra('acme/monorepo', $root, [
            'skills' => ['source' => ['packages/dto/skills', 'packages/auth/skills']],
        ]);

        Assert::count($donors, 2);
        Assert::same($donors[0]->source, 'packages/dto/skills');
        Assert::same($donors[1]->source, 'packages/auth/skills');
        Assert::same($donors[0]->packageName, 'acme/monorepo');
        Assert::same($donors[1]->packageName, 'acme/monorepo');
    }

    public function fromExtraThrowsWhenSourceListIsEmpty(): void
    {
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('must not be an empty list');

        (new VendorConfigMapper())->fromExtra(
            'acme/foo',
            Path::create(__DIR__),
            ['skills' => ['source' => []]],
        );
    }

    public function fromExtraThrowsWhenSourceListContainsNonString(): void
    {
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('extra.skills.source');

        (new VendorConfigMapper())->fromExtra(
            'acme/foo',
            Path::create(__DIR__),
            ['skills' => ['source' => ['resources/skills', 42]]],
        );
    }

    public function fromExtraThrowsWhenSourceListContainsDuplicates(): void
    {
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('must not contain duplicate entries');

        (new VendorConfigMapper())->fromExtra(
            'acme/foo',
            Path::create(__DIR__),
            ['skills' => ['source' => ['resources/skills', 'resources/skills']]],
        );
    }

    public function fromExtraThrowsWhenAnySourceListEntryEscapesPackageRoot(): void
    {
        // Every entry is held to the same rules as a single-string source: one
        // escaping entry poisons the whole declaration.
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('must not escape the package root');

        (new VendorConfigMapper())->fromExtra(
            'acme/foo',
            Path::create(__DIR__),
            ['skills' => ['source' => ['resources/skills', '../outside']]],
        );
    }

    public function fromExtraThrowsWhenExtraIsNotAnArray(): void
    {
        // Message must be specific to the "extra is not an array" branch so that
        // a missing `throw` here is not masked by later checks raising their own
        // MalformedVendorConfig under the same Expect::exception filter.
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('extra must be an object');

        (new VendorConfigMapper())->fromExtra('acme/foo', Path::create(__DIR__), 'not-an-array');
    }

    public function fromExtraThrowsWhenSkillsBlockIsWrongType(): void
    {
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('extra.skills must be an object');

        (new VendorConfigMapper())->fromExtra(
            'acme/foo',
            Path::create(__DIR__),
            ['skills' => 'not-an-object'],
        );
    }

    public function fromExtraThrowsWhenSourceIsMissing(): void
    {
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('extra.skills.source');

        (new VendorConfigMapper())->fromExtra(
            'acme/foo',
            Path::create(__DIR__),
            ['skills' => []],
        );
    }

    public function fromExtraThrowsWhenSourceIsEmptyString(): void
    {
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('extra.skills.source');

        (new VendorConfigMapper())->fromExtra(
            'acme/foo',
            Path::create(__DIR__),
            ['skills' => ['source' => '']],
        );
    }

    public function fromExtraThrowsWhenSourceIsNeitherStringNorList(): void
    {
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('extra.skills.source');

        (new VendorConfigMapper())->fromExtra(
            'acme/foo',
            Path::create(__DIR__),
            ['skills' => ['source' => 42]],
        );
    }

    public function fromExtraThrowsWhenSourceContainsDotDotSegment(): void
    {
        // `../outside` resolves below the package root — a malicious donor could
        // point sync at arbitrary files on disk. Must be rejected as malformed,
        // not silently accepted.
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('must not escape the package root');

        (new VendorConfigMapper())->fromExtra(
            'acme/foo',
            Path::create(__DIR__),
            ['skills' => ['source' => '../outside']],
        );
    }

    public function fromExtraThrowsWhenSourceContainsDotDotInMiddle(): void
    {
        // `..` resolution happens on the whole string, not just the leading
        // segment — `resources/../../etc` also escapes the package root.
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('must not escape the package root');

        (new VendorConfigMapper())->fromExtra(
            'acme/foo',
            Path::create(__DIR__),
            ['skills' => ['source' => 'resources/../../etc']],
        );
    }

    public function fromExtraThrowsWhenSourceIsAbsoluteUnixPath(): void
    {
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('must be a relative path');

        (new VendorConfigMapper())->fromExtra(
            'acme/foo',
            Path::create(__DIR__),
            ['skills' => ['source' => '/etc/passwd']],
        );
    }

    public function fromExtraThrowsWhenSourceIsAbsoluteWindowsPath(): void
    {
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('must be a relative path');

        (new VendorConfigMapper())->fromExtra(
            'acme/foo',
            Path::create(__DIR__),
            ['skills' => ['source' => 'C:\\Windows']],
        );
    }

    // ── declaresSources ──

    public function declaresSourcesTrueForNonEmptyList(): void
    {
        Assert::true(VendorConfigMapper::declaresSources([
            'skills' => ['sources' => [['from' => 'github', 'package' => 'acme/skills']]],
        ]));
    }

    public function declaresSourcesTrueEvenWhenEntriesAreInvalid(): void
    {
        // Shape-only on purpose: a botched list must still activate the
        // ref source so the parse error surfaces instead of the donor
        // being skipped silently.
        Assert::true(VendorConfigMapper::declaresSources(['skills' => ['sources' => ['garbage']]]));
    }

    public function declaresSourcesFalseForEmptyMissingOrNonListValues(): void
    {
        Assert::false(VendorConfigMapper::declaresSources(['skills' => ['sources' => []]]));
        Assert::false(VendorConfigMapper::declaresSources(['skills' => ['source' => 'skills']]));
        Assert::false(VendorConfigMapper::declaresSources(['skills' => ['sources' => 'not-a-list']]));
        Assert::false(VendorConfigMapper::declaresSources(['skills' => 'not-an-object']));
        Assert::false(VendorConfigMapper::declaresSources(null));
    }

    // ── sourceEntriesFromExtra ──

    public function sourceEntriesMapsHappyPath(): void
    {
        $entries = (new VendorConfigMapper())->sourceEntriesFromExtra('acme/foo', [
            'skills' => [
                'source' => 'resources/skills',
                'sources' => [
                    ['from' => 'github', 'package' => 'acme/skills', 'ref' => '^1.2', 'skills' => ['deploy']],
                    ['from' => 'gitlab', 'package' => 'acme/other'],
                ],
            ],
        ]);

        Assert::count($entries, 2);
        Assert::same($entries[0]->from, 'github');
        Assert::same($entries[0]->package, 'acme/skills');
        Assert::same($entries[0]->ref, '^1.2');
        Assert::same($entries[0]->skills, ['deploy']);
        Assert::same($entries[1]->from, 'gitlab');
    }

    public function sourceEntriesAllowsSelfVersionRef(): void
    {
        $entries = (new VendorConfigMapper())->sourceEntriesFromExtra('acme/foo', [
            'skills' => [
                'sources' => [['from' => 'github', 'package' => 'acme/skills', 'ref' => 'self.version']],
            ],
        ]);

        Assert::count($entries, 1);
        Assert::same($entries[0]->ref, 'self.version');
    }

    public function sourceEntriesReturnsEmptyWhenKeyAbsentOrExtraNotAnArray(): void
    {
        $mapper = new VendorConfigMapper();

        Assert::same($mapper->sourceEntriesFromExtra('acme/foo', ['skills' => ['source' => 'skills']]), []);
        Assert::same($mapper->sourceEntriesFromExtra('acme/foo', ['skills' => []]), []);
        Assert::same($mapper->sourceEntriesFromExtra('acme/foo', []), []);
        Assert::same($mapper->sourceEntriesFromExtra('acme/foo', 'not-an-array'), []);
    }

    public function sourceEntriesRejectsDirAdapterWithTailoredMessage(): void
    {
        // An in-package path is what `extra.skills.source` is for; a
        // vendor-controlled `dir` entry would point at the consumer's
        // filesystem and has no safe meaning.
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('extra.skills.sources[0].from "dir" is not allowed in a vendor package');

        (new VendorConfigMapper())->sourceEntriesFromExtra('acme/foo', [
            'skills' => ['sources' => [['from' => 'dir', 'path' => './skills']]],
        ]);
    }

    public function sourceEntriesRejectsDirAdapterEvenWhenTheEntryShapeIsOtherwiseBroken(): void
    {
        // The tailored message wins over whichever shape rule the shared
        // mapper would trip on first (here: a missing `path`).
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('not allowed in a vendor package');

        (new VendorConfigMapper())->sourceEntriesFromExtra('acme/foo', [
            'skills' => ['sources' => [['from' => 'dir']]],
        ]);
    }

    public function sourceEntriesThrowsOnUnknownAdapter(): void
    {
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('not a known source adapter');

        (new VendorConfigMapper())->sourceEntriesFromExtra('acme/foo', [
            'skills' => ['sources' => [['from' => 'nonsense', 'package' => 'acme/skills']]],
        ]);
    }

    public function sourceEntriesThrowsOnDuplicateCompositeKey(): void
    {
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('duplicates an earlier entry');

        (new VendorConfigMapper())->sourceEntriesFromExtra('acme/foo', [
            'skills' => ['sources' => [
                ['from' => 'github', 'package' => 'acme/skills'],
                ['from' => 'github', 'package' => 'acme/skills'],
            ]],
        ]);
    }

    public function sourceEntriesThrowsWhenSourcesIsNotAList(): void
    {
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('extra.skills.sources must be a list of objects');

        (new VendorConfigMapper())->sourceEntriesFromExtra('acme/foo', [
            'skills' => ['sources' => 'not-a-list'],
        ]);
    }

    public function sourceEntriesErrorMessagesCarryTheVendorFieldPath(): void
    {
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('extra.skills.sources[0].package is required');

        (new VendorConfigMapper())->sourceEntriesFromExtra('acme/foo', [
            'skills' => ['sources' => [['from' => 'github']]],
        ]);
    }

    public function fromExtraIgnoresTheSourcesKey(): void
    {
        // Local rows and external refs feed different discovery paths;
        // `fromExtra` must neither validate nor map `sources` — a fetched
        // archive's own `sources` list is never honoured (no transitive
        // remote chains).
        $donors = (new VendorConfigMapper())->fromExtra('acme/foo', Path::create(__DIR__), [
            'skills' => [
                'source' => 'resources/skills',
                'sources' => 'total garbage that must not be parsed here',
            ],
        ]);

        Assert::count($donors, 1);
        Assert::same($donors[0]->source, 'resources/skills');
    }
}
