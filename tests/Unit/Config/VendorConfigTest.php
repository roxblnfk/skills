<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config;

use Internal\Path;
use LLM\Skills\Config\VendorConfig;
use Testo\Assert;
use Testo\Test;

#[Test]
final class VendorConfigTest
{
    public function exposesConstructorArgsAsReadonlyProperties(): void
    {
        $root = Path::create(__DIR__);
        $cfg = new VendorConfig(
            packageName: 'acme/skills-pro',
            packageRoot: $root,
            source: 'resources/skills',
        );

        Assert::same($cfg->packageName, 'acme/skills-pro');
        Assert::same($cfg->source, 'resources/skills');
        Assert::same($cfg->packageRoot, $root);
    }

    public function sourcePathJoinsRootAndSource(): void
    {
        $root = Path::create(__DIR__);
        $cfg = new VendorConfig('acme/foo', $root, 'resources/skills');

        Assert::same(
            (string) $cfg->sourcePath(),
            (string) $root->join('resources/skills'),
        );
    }
}
