<?php

declare(strict_types=1);

namespace LLM\Skills\Unpacker;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Pick the best available {@see ArchiveUnpacker} for the current
 * machine.
 *
 * Selection rules (mirroring Composer's `ZipDownloader`):
 *
 * 1. If `ext-zip` is loaded → {@see ZipArchiveUnpacker}.
 * 2. Otherwise, if `proc_open()` is available, look up CLI extractors
 *    via Symfony's `ExecutableFinder` in this order:
 *      - Windows: `7z` (preferring `C:\Program Files\7-Zip`), then
 *        `unzip`;
 *      - Linux / macOS: `unzip`, then `7z`, `7zz`, `7za`.
 * 3. If neither path produces a usable extractor, return `null`. The
 *    caller (fetcher) turns that into a single per-ref warning that
 *    lists every tool that was checked, so the user knows what to
 *    install.
 *
 * The arguments-template for each CLI tool matches Composer's command
 * map verbatim — those flag combinations have been battle-tested
 * across platforms for years.
 *
 * @psalm-suppress MissingImmutableAnnotation queries the live environment
 */
final class UnpackerFactory
{
    /**
     * Names that are reported in the "nothing available" diagnostic
     * even when the lookup returns nothing.
     */
    private const REPORTED_CLI_TOOLS = ['unzip', '7z', '7zz', '7za'];

    private readonly ExecutableFinder $finder;

    /** @var \Closure(): bool */
    private readonly \Closure $hasZipArchive;

    /** @var \Closure(): bool */
    private readonly \Closure $hasProcOpen;

    private readonly bool $isWindows;

    /**
     * @param \Closure(): bool|null $hasZipArchive injected for testability — defaults to
     *         `class_exists(\ZipArchive::class)`.
     * @param \Closure(): bool|null $hasProcOpen   defaults to
     *         `function_exists('proc_open')`.
     */
    public function __construct(
        ?ExecutableFinder $finder = null,
        ?\Closure $hasZipArchive = null,
        ?\Closure $hasProcOpen = null,
        ?bool $isWindows = null,
    ) {
        $this->finder = $finder ?? new ExecutableFinder();
        $this->hasZipArchive = $hasZipArchive ?? static fn(): bool => \class_exists(\ZipArchive::class);
        $this->hasProcOpen = $hasProcOpen ?? static fn(): bool => \function_exists('proc_open');
        $this->isWindows = $isWindows ?? (\PHP_OS_FAMILY === 'Windows');
    }

    /**
     * Names of every CLI tool that was probed — surfaced in the
     * "nothing available" warning so the user knows what to install.
     *
     * @return list<non-empty-string>
     *
     * @psalm-pure
     */
    public static function reportedCliTools(): array
    {
        return self::REPORTED_CLI_TOOLS;
    }

    /**
     * Return the best available unpacker, or `null` when neither
     * `ext-zip` nor any supported CLI tool can be located.
     */
    public function detect(): ?ArchiveUnpacker
    {
        if (($this->hasZipArchive)()) {
            return new ZipArchiveUnpacker();
        }

        if (!($this->hasProcOpen)()) {
            return null;
        }

        return $this->detectCli();
    }

    /**
     * @psalm-mutation-free
     *
     * @psalm-suppress ImpureMethodCall ExecutableFinder::find probes
     *         the filesystem; we treat that as an allowed read for
     *         the purposes of "no state mutation".
     */
    private function detectCli(): ?ArchiveUnpacker
    {
        if ($this->isWindows) {
            // 7-Zip ships a Windows MSI / portable build that's the
            // closest thing to a default zip CLI on Windows. Prefer it
            // and pre-seed the known install dir like Composer does.
            $sevenZip = $this->finder->find('7z', null, ['C:\\Program Files\\7-Zip']);
            if ($sevenZip !== null && $sevenZip !== '') {
                return $this->build7z('7z', $sevenZip);
            }
            $unzip = $this->finder->find('unzip');
            if ($unzip !== null && $unzip !== '') {
                return $this->buildUnzip($unzip);
            }
            return null;
        }

        // Unix-like: Info-ZIP `unzip` first — it preserves UNIX
        // permissions and is the de-facto standard on Linux/macOS.
        $unzip = $this->finder->find('unzip');
        if ($unzip !== null && $unzip !== '') {
            return $this->buildUnzip($unzip);
        }
        foreach (['7z', '7zz', '7za'] as $candidate) {
            $path = $this->finder->find($candidate);
            if ($path !== null && $path !== '') {
                return $this->build7z($candidate, $path);
            }
        }
        return null;
    }

    /**
     * @param non-empty-string $path
     *
     * @psalm-pure
     */
    private function buildUnzip(string $path): CliUnpacker
    {
        // `-qq` quiet, `-d` target dir. Modern `unzip` strips leading
        // slashes and `..` segments on its own, but we do not rely on
        // it — entries are validated by the fetcher beforehand.
        return new CliUnpacker(
            id: 'unzip',
            executablePath: $path,
            extractArgsTemplate: ['-qq', '{file}', '-d', '{dir}'],
        );
    }

    /**
     * @param non-empty-string $id `7z`, `7zz`, or `7za`
     * @param non-empty-string $path
     *
     * @psalm-pure
     */
    private function build7z(string $id, string $path): CliUnpacker
    {
        // `x` = extract preserving paths, `-bb0` = silent, `-y` = yes
        // to all overwrite/skip prompts (the target dir is a fresh
        // scratch dir, so there's nothing to overwrite anyway).
        // `-o<dir>` is intentionally one token — 7z does not accept a
        // space between `-o` and the path.
        return new CliUnpacker(
            id: $id,
            executablePath: $path,
            extractArgsTemplate: ['x', '-bb0', '-y', '{file}', '-o{dir}'],
        );
    }
}
