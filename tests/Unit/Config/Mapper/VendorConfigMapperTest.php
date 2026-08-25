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
}
