<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Unpacker;

use LLM\Skills\Unpacker\CliUnpacker;
use LLM\Skills\Unpacker\UnpackerException;
use LLM\Skills\Unpacker\UnpackerFactory;
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

        $zipPath = $this->buildZip([
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

        $zipPath = $this->buildZip([
            'one.txt' => 'a',
            'dir/two.txt' => 'b',
        ]);

        Assert::same($unpacker->listEntries($zipPath), ['one.txt', 'dir/two.txt']);
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

    /**
     * @param array<string, string> $files
     */
    private function buildZip(array $files): string
    {
        $zipPath = $this->tmpDir . '/' . \bin2hex(\random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $zipPath;
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
