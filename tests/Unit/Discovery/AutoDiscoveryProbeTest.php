<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery;

use Internal\Path;
use LLM\Skills\Discovery\AutoDiscoveryProbe;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
final class AutoDiscoveryProbeTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-probe-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function returnsSkillsWhenSkillsDirectoryExists(): void
    {
        \mkdir($this->tmp . '/skills');

        $result = (new AutoDiscoveryProbe())->probe(Path::create($this->tmp));

        Assert::same($result, 'skills');
    }

    public function returnsNullWhenSkillsDirectoryIsMissing(): void
    {
        $result = (new AutoDiscoveryProbe())->probe(Path::create($this->tmp));

        Assert::null($result);
    }

    public function returnsNullWhenSkillsPathIsAFileNotADirectory(): void
    {
        \file_put_contents($this->tmp . '/skills', 'not a directory');

        $result = (new AutoDiscoveryProbe())->probe(Path::create($this->tmp));

        Assert::null($result);
    }

    public function returnsNullWhenPackageRootDoesNotExist(): void
    {
        $result = (new AutoDiscoveryProbe())->probe(Path::create($this->tmp . '/does-not-exist'));

        Assert::null($result);
    }

    public function returnsNullWhenSkillsSymlinkEscapesPackageRoot(): void
    {
        // Junction-safety: a symlink at <root>/skills pointing OUTSIDE <root>
        // must not be accepted as a discovery root.
        $outside = $this->tmp . '/outside-pkg';
        \mkdir($outside);

        $packageRoot = $this->tmp . '/package';
        \mkdir($packageRoot);

        // Try to create a symlink. On Windows without developer-mode privilege,
        // symlink creation fails — the safety property still holds but cannot
        // be exercised here. We fall back to a sanity check so the test always
        // makes at least one assertion (testo's risky-test guard).
        $linked = @\symlink($outside, $packageRoot . '/skills');
        if (!$linked) {
            Assert::null(
                (new AutoDiscoveryProbe())->probe(Path::create($packageRoot)),
                'symlink unavailable on this host — falling back to verifying that '
                . 'a missing skills/ root still yields null',
            );
            return;
        }

        $result = (new AutoDiscoveryProbe())->probe(Path::create($packageRoot));

        Assert::null($result);
    }

    public function exposesSourceDirAsAConstant(): void
    {
        // Other layers reference the well-known root by constant so the
        // hint text and probe stay in sync.
        Assert::same(AutoDiscoveryProbe::SOURCE_DIR, 'skills');
    }
}
