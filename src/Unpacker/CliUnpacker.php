<?php

declare(strict_types=1);

namespace LLM\Skills\Unpacker;

use Symfony\Component\Process\Process;

/**
 * CLI-tool unpacker — invokes one of `unzip` / `7z` / `7zz` / `7za` to
 * extract the archive. Used as a fallback on machines that don't ship
 * `ext-zip` (stripped-down Docker images, some shared hostings,
 * occasional Windows installations).
 *
 * The cascade and argument shapes mirror Composer's `ZipDownloader` so
 * we benefit from the same platform conventions:
 *
 * - `unzip -qq <file> -d <path>` — Info-ZIP, primary on Linux/macOS.
 * - `7z x -bb0 -y <file> -o<path>` — full 7-Zip, primary on Windows.
 * - `7zz` / `7za` — 7-Zip variants used on Linux/macOS when `unzip`
 *   and `7z` are absent (`7zz` is the SourceForge build, `7za` the
 *   stand-alone "p7zip-full" build).
 *
 * Entry-name validation is **not** delegated to the CLI tool:
 * {@see ZipCentralDirectoryReader} reads the archive's Central
 * Directory directly and the fetcher applies its lexical zip-slip
 * check before this unpacker runs. CLI tools have no built-in
 * zip-slip protection and apply `-y`-style overwrite by default;
 * validating ourselves is the only safe approach.
 *
 * @psalm-suppress MissingImmutableAnnotation
 *         stateless wrapper, but `listEntries`/`extractTo` perform I/O
 */
final readonly class CliUnpacker implements ArchiveUnpacker
{
    /**
     * @param non-empty-string $id short identifier (`unzip`, `7z`, …) used in errors
     * @param non-empty-string $executablePath absolute path to the binary
     * @param non-empty-list<string> $extractArgsTemplate argv template with `{file}` / `{dir}` placeholders.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        private string $id,
        private string $executablePath,
        private array $extractArgsTemplate,
        private ZipCentralDirectoryReader $cdReader = new ZipCentralDirectoryReader(),
        /**
         * Hard cap on `Process::setTimeout()` — extraction of a typical
         * donor archive (tens of skill files, < 1 MiB unpacked) runs in
         * well under a second; 120 s is the same ceiling the live
         * acceptance test uses for the wrapping `composer skills:add`.
         *
         * @var int<1, max>
         */
        private int $timeout = 120,
    ) {}

    /**
     * @psalm-mutation-free
     */
    #[\Override]
    public function id(): string
    {
        return $this->id;
    }

    #[\Override]
    public function listEntries(string $zipPath): array
    {
        return $this->cdReader->readNames($zipPath);
    }

    #[\Override]
    public function extractTo(string $zipPath, string $targetDir): void
    {
        $argv = [$this->executablePath];
        foreach ($this->extractArgsTemplate as $arg) {
            $argv[] = \strtr($arg, [
                '{file}' => $zipPath,
                '{dir}' => $targetDir,
            ]);
        }

        $process = new Process($argv);
        $process->setTimeout($this->timeout);

        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new UnpackerException(
                \sprintf('failed to invoke %s: %s', $this->id, $e->getMessage()),
                previous: $e,
            );
        }

        if (!$process->isSuccessful()) {
            throw new UnpackerException(\sprintf(
                'extractor %s exited with code %d: %s',
                $this->id,
                (int) $process->getExitCode(),
                \trim($process->getErrorOutput() ?: $process->getOutput()),
            ));
        }
    }
}
