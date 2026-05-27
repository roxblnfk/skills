<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Unpacker;

use LLM\Skills\Unpacker\UnpackerException;
use LLM\Skills\Unpacker\ZipCentralDirectoryReader;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Unit coverage for the hand-rolled Central Directory parser.
 *
 * The parser owns the zip-slip safety boundary for the CLI fallback:
 * if it misreads or skips entries, the fetcher cannot validate them
 * lexically before invoking `unzip` / `7z`. These tests pin every
 * failure mode that would break that contract.
 *
 * Fixture creation uses `\ZipArchive` itself — the reader's
 * correctness is asserted against archives produced by the canonical
 * zip implementation, and the whole suite self-skips when `ext-zip`
 * isn't available (we have nothing to build fixtures with).
 */
#[Test]
#[Covers(ZipCentralDirectoryReader::class)]
final class ZipCentralDirectoryReaderTest
{
    private string $tmpDir;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/llm-skills-cdr-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmpDir, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->cleanup($this->tmpDir);
    }

    public function readsEntryNamesInArchiveOrder(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }

        $zipPath = $this->buildZip([
            'first.txt' => 'a',
            'sub/second.txt' => 'bb',
            'sub/third.txt' => 'ccc',
        ]);

        $names = (new ZipCentralDirectoryReader())->readNames($zipPath);
        Assert::same($names, ['first.txt', 'sub/second.txt', 'sub/third.txt']);
    }

    public function survivesAnArchiveCommentThatLooksLikeAnEocdSignature(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }

        // The EOCD-locator must not lock onto a `PK\x05\x06` signature
        // that lives inside the archive comment (which trails the real
        // EOCD). The locator scans every candidate from right to left
        // and accepts only the one whose `comment_len` field is
        // self-consistent — the fake match cannot satisfy that math.
        $zip = new \ZipArchive();
        $zipPath = $this->tmpDir . '/with-comment.zip';
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('only.txt', 'x');
        $zip->setArchiveComment("PK\x05\x06 trailing bytes");
        $zip->close();

        $names = (new ZipCentralDirectoryReader())->readNames($zipPath);
        Assert::same($names, ['only.txt']);
    }

    public function survivesAFullSyntheticEocdEmbeddedInTheArchiveComment(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }

        // Stronger version of the previous case: the archive comment
        // contains a *complete* 22-byte EOCD-shaped block whose own
        // `comment_len` field claims zero trailing bytes. A naive
        // `strrpos`-based locator would pick this fake EOCD (it sits
        // after the real one) and the bogus offsets would steer the
        // CD parse into nonsense bytes. The robust locator must reject
        // it because the math (`candidate + 22 + 0` == tailLen) only
        // holds for the trailing match, but the fake's interior
        // offsets are inconsistent with the real CD — so it falls back
        // to the leftward, real EOCD.
        $fakeEocd = "\x50\x4b\x05\x06" // signature
            . \str_repeat("\x00", 16)  // disks + entries + sizes (all zero)
            . "\x00\x00";               // comment_len = 0

        $zip = new \ZipArchive();
        $zipPath = $this->tmpDir . '/fake-eocd-in-comment.zip';
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('a.txt', 'a');
        $zip->addFromString('b.txt', 'b');
        $zip->setArchiveComment('padding ' . $fakeEocd . ' padding');
        $zip->close();

        $names = (new ZipCentralDirectoryReader())->readNames($zipPath);
        \sort($names);
        Assert::same($names, ['a.txt', 'b.txt']);
    }

    public function rejectsAFileThatHasNoEocdSignature(): void
    {
        $bogus = $this->tmpDir . '/not-a-zip.bin';
        \file_put_contents($bogus, \str_repeat('A', 200));

        Expect::exception(UnpackerException::class)
            ->withMessageContaining('end-of-central-directory record not found');

        (new ZipCentralDirectoryReader())->readNames($bogus);
    }

    public function rejectsAFileTooSmallToBeAValidZip(): void
    {
        $tiny = $this->tmpDir . '/tiny.zip';
        \file_put_contents($tiny, 'PK');

        Expect::exception(UnpackerException::class)
            ->withMessageContaining('too small');

        (new ZipCentralDirectoryReader())->readNames($tiny);
    }

    public function preservesUnsafeEntryNamesVerbatimSoTheFetcherCanRejectThem(): void
    {
        if (!\class_exists(\ZipArchive::class)) {
            throw new SkipTest('ext-zip unavailable — cannot build fixture archive');
        }

        // The reader is intentionally a transparent enumerator —
        // the lexical zip-slip check lives in the fetcher. This test
        // documents that contract: `../../escape.txt` and absolute
        // paths come back as-is, not normalised or rejected here.
        $zip = new \ZipArchive();
        $zipPath = $this->tmpDir . '/unsafe.zip';
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('safe.txt', 'ok');
        $zip->addFromString('../escape.txt', 'pwn');
        $zip->close();

        $names = (new ZipCentralDirectoryReader())->readNames($zipPath);
        Assert::same($names, ['safe.txt', '../escape.txt']);
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
