<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Unpacker;

use LLM\Skills\Unpacker\CliUnpacker;
use LLM\Skills\Unpacker\UnpackerFactory;
use LLM\Skills\Unpacker\ZipArchiveUnpacker;
use Symfony\Component\Process\ExecutableFinder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Unit coverage for the unpacker auto-selection rules.
 *
 * The factory is the single decision point that picks between the
 * `\ZipArchive` path and a CLI fallback; the rules mirror Composer's
 * `ZipDownloader` cascade and these tests pin every branch:
 *
 * - ext-zip wins when present;
 * - without ext-zip, Unix prefers `unzip` over `7z*`, Windows prefers
 *   `7z` over `unzip`;
 * - without `proc_open()`, no CLI fallback is offered;
 * - when nothing is available, `detect()` returns `null` so the
 *   fetcher can synthesise a single "install one of …" warning.
 */
#[Test]
#[Covers(UnpackerFactory::class)]
final class UnpackerFactoryTest
{
    public function picksZipArchiveWhenExtensionIsLoaded(): void
    {
        $factory = new UnpackerFactory(
            finder: new ExecutableFinder(),
            hasZipArchive: static fn(): bool => true,
            hasProcOpen: static fn(): bool => true,
            isWindows: false,
        );

        $unpacker = $factory->detect();
        Assert::true($unpacker instanceof ZipArchiveUnpacker);
    }

    public function picksUnzipBeforeSevenZipOnUnixWhenExtZipMissing(): void
    {
        $factory = new UnpackerFactory(
            finder: self::stubFinder([
                'unzip' => '/usr/bin/unzip',
                '7z' => '/usr/bin/7z',
                '7zz' => '/usr/bin/7zz',
            ]),
            hasZipArchive: static fn(): bool => false,
            hasProcOpen: static fn(): bool => true,
            isWindows: false,
        );

        $unpacker = $factory->detect();
        Assert::true($unpacker instanceof CliUnpacker);
        Assert::same($unpacker->id(), 'unzip');
    }

    public function fallsBackTo7zOn7zzOn7zaOnUnixWhenUnzipIsAbsent(): void
    {
        $factory = new UnpackerFactory(
            finder: self::stubFinder(['7zz' => '/opt/7zz/7zz']),
            hasZipArchive: static fn(): bool => false,
            hasProcOpen: static fn(): bool => true,
            isWindows: false,
        );

        $unpacker = $factory->detect();
        Assert::true($unpacker instanceof CliUnpacker);
        Assert::same($unpacker->id(), '7zz');
    }

    public function picksSevenZipBeforeUnzipOnWindowsWhenExtZipMissing(): void
    {
        // 7-Zip is the de-facto default zip CLI on Windows; the
        // factory must mirror Composer's command-map preference here
        // so behaviour matches the user's expectation when they have
        // both tools installed.
        $factory = new UnpackerFactory(
            finder: self::stubFinder([
                '7z' => 'C:\\Program Files\\7-Zip\\7z.exe',
                'unzip' => 'C:\\Tools\\unzip.exe',
            ]),
            hasZipArchive: static fn(): bool => false,
            hasProcOpen: static fn(): bool => true,
            isWindows: true,
        );

        $unpacker = $factory->detect();
        Assert::true($unpacker instanceof CliUnpacker);
        Assert::same($unpacker->id(), '7z');
    }

    public function returnsNullWhenProcOpenIsDisabled(): void
    {
        // proc_open is the only way to drive a CLI extractor; without
        // it the factory must refuse to fall back even when the
        // executables are present, because we couldn't run them.
        $factory = new UnpackerFactory(
            finder: self::stubFinder(['unzip' => '/usr/bin/unzip']),
            hasZipArchive: static fn(): bool => false,
            hasProcOpen: static fn(): bool => false,
            isWindows: false,
        );

        Assert::same($factory->detect(), null);
    }

    public function returnsNullWhenNoExtractorIsAvailableAtAll(): void
    {
        $factory = new UnpackerFactory(
            finder: self::stubFinder([]),
            hasZipArchive: static fn(): bool => false,
            hasProcOpen: static fn(): bool => true,
            isWindows: false,
        );

        Assert::same($factory->detect(), null);
    }

    public function reportedCliToolsListsEveryProbedFallback(): void
    {
        Assert::same(
            UnpackerFactory::reportedCliTools(),
            ['unzip', '7z', '7zz', '7za'],
        );
    }

    /**
     * Minimal `ExecutableFinder` stub — only the `find($name)` method
     * is used by the factory, and only the first argument matters for
     * our tests (the optional default + extra-dirs args are ignored).
     *
     * @param array<string, string> $known map of `find()` argument → returned path
     */
    private static function stubFinder(array $known): ExecutableFinder
    {
        return new class($known) extends ExecutableFinder {
            /** @param array<string, string> $known */
            public function __construct(private array $known) {}

            #[\Override]
            public function find(string $name, ?string $default = null, array $extraDirs = []): ?string
            {
                return $this->known[$name] ?? $default;
            }
        };
    }
}
