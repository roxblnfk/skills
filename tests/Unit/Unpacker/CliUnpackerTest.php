<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Unpacker;

use LLM\Skills\Tests\Fixture\ZipFixtures;
use LLM\Skills\Unpacker\CliUnpacker;
use LLM\Skills\Unpacker\UnpackerException;
use LLM\Skills\Unpacker\UnpackerFactory;
use LLM\Skills\Unpacker\ZipEntry;
use Symfony\Component\Process\ExecutableFinder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Integration-flavoured unit tests for the CLI unpacker.
 *
 * The class drives a real `unzip` / `7z*` subprocess against a real
 * fixture archive — these tests only run when at least one of those
 * executables is on PATH. They self-skip otherwise; CI without the
 * tools still passes.
 *
 * Building the fixture archive depends on `\ZipArchive`, so the suite
 * also self-skips when `ext-zip` is absent.
 */
#[Test]
#[Covers(CliUnpacker::class)]
final class CliUnpackerTest
{
    use ZipFixtures;

    private string $tmpDir;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/llm-skills-cli-unpacker-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmpDir, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->cleanup($this->tmpDir);
    }

    public function liveCliExtractorReplicatesArchiveLayout(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }
        $unpacker = $this->pickCliUnpackerOrSkip();

        $zipPath = self::buildZipIn($this->tmpDir, [
            'root.txt' => 'top-level',
            'sub/nested.txt' => 'nested content',
        ]);

        $target = $this->tmpDir . '/out';
        \mkdir($target, 0o777, true);
        $unpacker->extractTo($zipPath, $target);

        Assert::true(\is_file($target . '/root.txt'));
        Assert::true(\is_file($target . '/sub/nested.txt'));
        Assert::same(\file_get_contents($target . '/sub/nested.txt'), 'nested content');
    }

    public function liveCliExtractorListsEntriesViaCdrReader(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }
        $unpacker = $this->pickCliUnpackerOrSkip();

        $zipPath = self::buildZipIn($this->tmpDir, [
            'one.txt' => 'a',
            'dir/two.txt' => 'b',
        ]);

        Assert::same(
            \array_map(static fn(ZipEntry $e): string => $e->name, $unpacker->listEntries($zipPath)),
            ['one.txt', 'dir/two.txt'],
        );
    }

    public function liveCliExtractorSkipsExcludedSymlinkEntriesInsteadOfFailing(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }
        $unpacker = $this->pickCliUnpackerOrSkip();

        // Reproduces the reported break: a donor shipping AGENTS.md as
        // a symlink to CLAUDE.md, nested under the top-level directory
        // every GitHub zipball wraps its contents in. 7-Zip on Windows
        // cannot create the link without SeCreateSymbolicLinkPrivilege
        // and used to exit 2, taking the entire donor down with it. The
        // nesting matters: the exclusion pattern must match a name with
        // a `/` separator against the real tool.
        $zipPath = self::buildUnixZipIn(
            $this->tmpDir,
            [
                'acme-skills-v1/CLAUDE.md' => 'real content',
                'acme-skills-v1/AGENTS.md' => 'CLAUDE.md',
                'acme-skills-v1/skills/hello/SKILL.md' => 'hi',
            ],
            symlinks: ['acme-skills-v1/AGENTS.md'],
        );

        // Mirror the fetcher's contract: symlink entries flagged by
        // listEntries() are handed back to extractTo() as exclusions.
        $exclude = [];
        foreach ($unpacker->listEntries($zipPath) as $entry) {
            if ($entry->isSymlink) {
                $exclude[] = $entry->name;
            }
        }
        Assert::same($exclude, ['acme-skills-v1/AGENTS.md']);

        $target = $this->tmpDir . '/out-symlink';
        \mkdir($target, 0o777, true);

        $unpacker->extractTo($zipPath, $target, $exclude);

        Assert::true(
            \is_file($target . '/acme-skills-v1/CLAUDE.md'),
            'a symlink entry must not stop the rest of the archive from extracting',
        );
        Assert::same(\file_get_contents($target . '/acme-skills-v1/CLAUDE.md'), 'real content');
        Assert::true(\is_file($target . '/acme-skills-v1/skills/hello/SKILL.md'));
        Assert::false(
            \file_exists($target . '/acme-skills-v1/AGENTS.md') || \is_link($target . '/acme-skills-v1/AGENTS.md'),
            'the excluded symlink entry must not be extracted',
        );
    }

    public function liveCliExtractorNeverPassesOptionOrWildcardShapedExclusions(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }
        $unpacker = $this->pickCliUnpackerOrSkip();

        $zipPath = self::buildZipIn($this->tmpDir, [
            'root.txt' => 'top-level',
            'sub/keep.txt' => 'kept',
            'sub/skip.txt' => 'skipped',
        ]);

        $target = $this->tmpDir . '/out-guard';
        \mkdir($target, 0o777, true);

        // `-j` reads as an Info-ZIP option (junk paths) and `sub/*` as
        // a wildcard covering keep.txt — passing either verbatim would
        // corrupt or over-shrink the extraction. Both must be dropped
        // from the argv; only the literal name may take effect.
        $unpacker->extractTo($zipPath, $target, ['-j', 'sub/*', 'sub/skip.txt']);

        Assert::true(\is_file($target . '/root.txt'));
        Assert::true(
            \is_file($target . '/sub/keep.txt'),
            'a wildcard-shaped exclusion must not widen to sibling entries',
        );
        Assert::false(\file_exists($target . '/sub/skip.txt'));
    }

    public function failedExtractionSurfacesAsUnpackerException(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }
        $unpacker = $this->pickCliUnpackerOrSkip();

        // Feed a non-zip file — the CLI tool will exit non-zero and
        // the unpacker must translate that into UnpackerException
        // (the fetcher's translation layer relies on this).
        $bogus = $this->tmpDir . '/junk.zip';
        \file_put_contents($bogus, \str_repeat('A', 200));

        $target = $this->tmpDir . '/out';
        \mkdir($target, 0o777, true);

        Expect::exception(UnpackerException::class);

        $unpacker->extractTo($bogus, $target);
    }

    private function pickCliUnpackerOrSkip(): CliUnpacker
    {
        if (!\function_exists('proc_open')) {
            throw new SkipTest('proc_open unavailable — cannot drive a CLI extractor');
        }
        $factory = new UnpackerFactory(
            finder: new ExecutableFinder(),
            hasZipArchive: static fn(): bool => false,
            hasProcOpen: static fn(): bool => true,
        );
        $unpacker = $factory->detect();
        if (!$unpacker instanceof CliUnpacker) {
            throw new SkipTest('no CLI extractor (unzip / 7z / 7zz / 7za) on PATH');
        }
        return $unpacker;
    }

    private function cleanup(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);
            return;
        }
        $entries = @\scandir($path) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->cleanup($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
