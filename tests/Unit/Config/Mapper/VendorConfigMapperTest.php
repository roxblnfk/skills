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

        $cfg = $mapper->fromExtra('acme/skills-pro', $root, [
            'skills' => ['source' => 'resources/skills'],
        ]);

        Assert::same($cfg->packageName, 'acme/skills-pro');
        Assert::same($cfg->source, 'resources/skills');
        Assert::same((string) $cfg->packageRoot, (string) $root);
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

    public function fromExtraThrowsWhenSourceIsNotAString(): void
    {
        Expect::exception(MalformedVendorConfig::class)
            ->withMessageContaining('extra.skills.source');

        (new VendorConfigMapper())->fromExtra(
            'acme/foo',
            Path::create(__DIR__),
            ['skills' => ['source' => ['nested']]],
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
