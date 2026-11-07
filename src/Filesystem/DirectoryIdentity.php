<?php

declare(strict_types=1);

namespace LLM\Skills\Filesystem;

use Internal\Path;

/**
 * One existing directory, recognised under any spelling of its path.
 *
 * Built once and matched against many paths, so a tree walk pays a single
 * `stat()` per entry. Device and inode identify the directory whatever case or
 * spelling a path uses; where the file system reports no inode, the resolved
 * paths are compared instead.
 *
 * @psalm-immutable
 */
final readonly class DirectoryIdentity
{
    /**
     * @psalm-mutation-free
     */
    private function __construct(
        private Path $real,
        private int $device,
        private int $inode,
    ) {}

    /**
     * `null` when `$dir` does not exist.
     *
     * @psalm-pure
     *
     * @psalm-suppress ImpureFunctionCall the directory is read once, when the identity is taken
     */
    public static function of(Path $dir): ?self
    {
        $real = \realpath((string) $dir);
        $stat = $real === false || $real === '' ? false : @\stat($real);
        if ($real === false || $real === '' || $stat === false) {
            return null;
        }

        return new self(Path::create($real), $stat['dev'], $stat['ino']);
    }

    /**
     * @psalm-suppress ImpureFunctionCall, ImpureMethodCall reads the file system by design
     */
    public function matches(Path $path): bool
    {
        if ($this->inode !== 0) {
            $stat = @\stat((string) $path);

            return $stat !== false && $stat['ino'] === $this->inode && $stat['dev'] === $this->device;
        }

        return PathContainment::isSame($path, $this->real);
    }
}
