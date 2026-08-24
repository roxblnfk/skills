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
 * Exclusions are expressed per tool (`-x!<path>` for 7-Zip, a `-x`
 * list for Info-ZIP) rather than with 7-Zip's `-snl-` (skip symlinks),
 * which only exists from 21.02 and would break the p7zip 16.02 builds
 * still shipping as `7za` on older distributions. Both tools treat the
 * exclusion argument as a wildcard pattern, not a literal name — see
 * {@see self::isSafeExclusionName()} for how that is contained.
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
     *        paths, when anything is excluded. Info-ZIP takes a single `-x` that opens a
     *        list; 7-Zip needs no opener, so this is empty for it. Nothing is appended
     *        when nothing is excluded.
     * @param non-empty-string $excludePathTemplate per-path template with a `{path}`
     *        placeholder — `-x!{path}` for 7-Zip, `{path}` for Info-ZIP.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        private string $id,
        private string $executablePath,
        private array $extractArgsTemplate,
        private array $excludeArgsTemplate,
        private string $excludePathTemplate,
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
        return $this->cdReader->readEntries($zipPath);
    }

    #[\Override]
    public function extractTo(string $zipPath, string $targetDir, array $excludeNames = []): void
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
        foreach ($this->buildExclusionArgs($excludeNames) as $arg) {
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
     * Whether an entry name can be passed to the CLI tool as an
     * exclusion without changing the command's meaning:
     *
     * - a leading `-` reads as an option — Info-ZIP terminates its `-x`
     *   list at the next switch-looking token and would apply the name
     *   as a flag (`-j`, `-d …`) instead of an exclusion;
     * - `*` / `?` / `[` are wildcard metacharacters in both tools'
     *   exclusion patterns, so such a name could exclude entries other
     *   than itself (silently dropping wanted files);
     * - `\` doubles as a path separator / escape on Windows tool
     *   builds, making the match platform-dependent.
     *
     * Neither tool offers list-terminating `--` semantics or a
     * universally supported literal-match switch inside the supported
     * version range, so unexpressible names are simply not excluded.
     *
     * @psalm-pure
     */
    private static function isSafeExclusionName(string $name): bool
    {
        return !\str_starts_with($name, '-') && \strpbrk($name, '*?[\\') === false;
    }

    /**
     * Argv tail excluding the given entry names, or an empty list when
     * nothing (expressible) is excluded.
     *
     * Names that {@see self::isSafeExclusionName()} rejects are dropped
     * from the exclusion list, not from the archive: the tool extracts
     * them like any other entry. That keeps a hostile name from
     * touching the command line while never widening an exclusion
     * beyond what the caller asked for.
     *
     * @param list<string> $excludeNames
     *
     * @return list<string>
     *
     * @psalm-mutation-free
     */
    private function buildExclusionArgs(array $excludeNames): array
    {
        $paths = [];
        foreach ($excludeNames as $name) {
            if (self::isSafeExclusionName($name)) {
                $paths[] = \strtr($this->excludePathTemplate, ['{path}' => $name]);
            }
        }

        return $paths === [] ? [] : [...$this->excludeArgsTemplate, ...$paths];
    }
}
