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
    public function declaresSkillsTrueForArrayWithSkillsKey(): void
    {
        Assert::true(VendorConfigMapper::declaresSkills(['skills' => ['source' => 'a']]));
    }

    public function declaresSkillsTrueEvenForBrokenSkillsBlock(): void
    {
        // A donor with a broken block is still a donor — we want to surface a
        // warning, not silently treat it as a non-donor.
        Assert::true(VendorConfigMapper::declaresSkills(['skills' => 'not-an-object']));
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
}
