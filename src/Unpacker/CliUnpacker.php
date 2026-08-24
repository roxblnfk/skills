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
 * **Symlink entries are excluded from the extraction.** Recreating a
 * symlink on Windows needs `SeCreateSymbolicLinkPrivilege`, which an
 * unelevated process outside Developer Mode does not hold; 7-Zip treats
 * the refusal as a fatal error and exits non-zero, losing the whole
 * archive over a single link. Excluding the entries up front sidesteps
 * that, and costs nothing downstream: {@see \LLM\Skills\Filesystem\LinkGuard}
 * refuses to copy symlinks into the target anyway, so a link that did
 * extract could never reach a skill directory. The exclusion is spelled
 * per tool (`-x!<path>` for 7-Zip, `-x <path>` for Info-ZIP) rather than
 * with 7-Zip's `-snl-`, which only exists from 21.02 and would break the
 * p7zip 16.02 builds still shipping as `7za` on older distributions.
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
     * @param list<string> $excludeArgsTemplate tokens appended once, ahead of the excluded
     *        paths, when the archive holds any symlink entry. Info-ZIP takes a single `-x`
     *        that opens a list; 7-Zip needs no opener, so this is empty for it. Nothing is
     *        appended when the archive has no symlinks.
     * @param non-empty-string|null $excludePathTemplate per-path template with a `{path}`
     *        placeholder — `-x!{path}` for 7-Zip, `{path}` for Info-ZIP. `null` marks a tool
     *        that cannot express exclusions, in which case extraction runs unfiltered.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        private string $id,
        private string $executablePath,
        private array $extractArgsTemplate,
        private array $excludeArgsTemplate = [],
        private ?string $excludePathTemplate = null,
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

        // Exclusions go last: Info-ZIP's `-x` opens a list that runs to
        // the end of the command line, so it cannot precede `{file}` or
        // `-d {dir}`. 7-Zip accepts `-x!` anywhere, so one ordering
        // serves both.
        foreach ($this->buildExclusions($zipPath) as $arg) {
            $argv[] = $arg;
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

    /**
     * Argv tail excluding every symlink entry in the archive, or an
     * empty list when there are none (the overwhelmingly common case)
     * or when the tool has no exclusion syntax.
     *
     * A malformed Central Directory is swallowed here rather than
     * raised: the fetcher already ran {@see self::listEntries()} over
     * the same archive and would have rejected it, so a throw at this
     * point could only turn a readable archive into a failure. Losing
     * the exclusions degrades to the previous behaviour.
     *
     * @param non-empty-string $zipPath
     *
     * @return list<string>
     */
    private function buildExclusions(string $zipPath): array
    {
        if ($this->excludePathTemplate === null) {
            return [];
        }

        try {
            $entries = $this->cdReader->readEntries($zipPath);
        } catch (UnpackerException) {
            return [];
        }

        $paths = [];
        foreach ($entries as $entry) {
            if ($entry->isSymlink) {
                // 7-Zip reads `-x!` patterns as wildcards, so an entry
                // name containing `*` or `?` can exclude more than
                // itself. Over-excluding only ever drops files the copy
                // step would not have wanted (and cannot widen what
                // gets written), so it is left as-is rather than
                // guarded with a version-dependent literal-match switch.
                $paths[] = \strtr($this->excludePathTemplate, ['{path}' => $entry->name]);
            }
        }

        return $paths === [] ? [] : [...$this->excludeArgsTemplate, ...$paths];
    }
}
