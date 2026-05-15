<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Sync;

use Internal\Path;
use LLM\Skills\Sync\LinkStatus;
use LLM\Skills\Sync\SymlinkLinker;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for {@see SymlinkLinker} — the §4.2 state matrix from the
 * multi-target spec.
 *
 * Each test runs against its own temporary tree:
 *
 *   <tmp>/
 *     target/       — the directory the alias should point at
 *     other/        — a second real dir, used for "points elsewhere" cases
 *
 * The {@see BeforeTest} hook creates an empty `<tmp>` plus `target/`;
 * `<tmp>/other/` is created on demand by tests that need it. The
 * {@see AfterTest} hook tears the tree down with the junction-safe
 * {@see Filesystem::removeRecursive()} helper so a stray junction never
 * deletes anything outside the tmp tree.
 */
#[Test]
final class SymlinkLinkerTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-link-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
        \mkdir($this->tmp . '/target', 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function createsLinkWhenAliasPathDoesNotExist(): void
    {
        $aliasPath = $this->tmp . '/alias';
        $result = $this->linker()->link(Path::create($aliasPath), $this->target());

        Assert::same($result->status, LinkStatus::Created);
        Assert::true(\file_exists($aliasPath), 'alias path must exist after create');
        Assert::true(
            $this->resolvesToTarget($aliasPath),
            'alias must resolve to the target (i.e. it is a junction/symlink, not a real dir)',
        );
    }

    public function linkResolvesToTheTarget(): void
    {
        // After linking, files written into the target must be visible
        // through the alias — proves the link actually points where we
        // claimed, on both Windows junctions and POSIX symlinks.
        $aliasPath = $this->tmp . '/alias';
        $this->linker()->link(Path::create($aliasPath), $this->target());

        \file_put_contents($this->tmp . '/target/marker.txt', 'hello');

        Assert::true(\is_file($aliasPath . '/marker.txt'));
        Assert::same(\file_get_contents($aliasPath . '/marker.txt'), 'hello');
    }

    public function existingLinkPointingAtTargetIsNoOp(): void
    {
        $aliasPath = $this->tmp . '/alias';
        $linker = $this->linker();

        $first = $linker->link(Path::create($aliasPath), $this->target());
        $second = $linker->link(Path::create($aliasPath), $this->target());

        Assert::same($first->status, LinkStatus::Created);
        Assert::same($second->status, LinkStatus::AlreadyCorrect);
    }

    public function existingLinkPointingElsewhereFails(): void
    {
        // Make the alias point at <tmp>/other first, then ask the linker
        // to point it at <tmp>/target. The linker must refuse rather than
        // silently retarget the user's existing alias.
        \mkdir($this->tmp . '/other', 0o777, true);
        $aliasPath = $this->tmp . '/alias';
        $linker = $this->linker();

        $linker->link(Path::create($aliasPath), Path::create($this->tmp . '/other'));
        $result = $linker->link(Path::create($aliasPath), $this->target());

        Assert::same($result->status, LinkStatus::Failed);
        Assert::true($result->isFailure());
        Assert::true(
            $result->reason !== null && \str_contains($result->reason, 'points elsewhere'),
            'reason should mention "points elsewhere"; got: ' . ($result->reason ?? 'null'),
        );
    }

    public function existingRealDirectoryFailsAndIsNotTouched(): void
    {
        $aliasPath = $this->tmp . '/alias';
        \mkdir($aliasPath, 0o777, true);
        \file_put_contents($aliasPath . '/user-file.txt', 'precious');

        $result = $this->linker()->link(Path::create($aliasPath), $this->target());

        Assert::same($result->status, LinkStatus::Failed);
        Assert::true(\is_dir($aliasPath));
        Assert::true(\is_file($aliasPath . '/user-file.txt'));
        Assert::same(\file_get_contents($aliasPath . '/user-file.txt'), 'precious');
    }

    public function existingRegularFileFails(): void
    {
        $aliasPath = $this->tmp . '/alias';
        \file_put_contents($aliasPath, 'i am a file, not a directory');

        $result = $this->linker()->link(Path::create($aliasPath), $this->target());

        Assert::same($result->status, LinkStatus::Failed);
        Assert::true(\is_file($aliasPath), 'regular file must be left untouched');
    }

    public function missingTargetFails(): void
    {
        $result = $this->linker()->link(
            Path::create($this->tmp . '/alias'),
            Path::create($this->tmp . '/does-not-exist'),
        );

        Assert::same($result->status, LinkStatus::Failed);
        Assert::true(
            $result->reason !== null && \str_contains($result->reason, 'target directory does not exist'),
            'reason should mention the missing target; got: ' . ($result->reason ?? 'null'),
        );
    }

    public function dryRunReportsWouldCreateWithoutWriting(): void
    {
        $aliasPath = $this->tmp . '/alias';
        $result = $this->linker()->link(Path::create($aliasPath), $this->target(), dryRun: true);

        Assert::same($result->status, LinkStatus::WouldCreate);
        Assert::false(\file_exists($aliasPath), 'dry-run must not create the link');
        Assert::false($this->resolvesToTarget($aliasPath));
    }

    public function dryRunStillReportsCollisionsWithExistingDirectory(): void
    {
        // The state-matrix collisions in §4.2 are reported the same way
        // in dry-run and normal mode — the user can't tell from a dry-run
        // alone whether the conflict has been resolved.
        $aliasPath = $this->tmp . '/alias';
        \mkdir($aliasPath, 0o777, true);

        $result = $this->linker()->link(Path::create($aliasPath), $this->target(), dryRun: true);

        Assert::same($result->status, LinkStatus::Failed);
    }

    public function createsParentDirectoryWhenMissing(): void
    {
        // Aliases inside a not-yet-existing parent (e.g. first-time
        // `.config/agents/skills`) should not require the user to mkdir
        // the parent manually. SyncEngine does this for the target, the
        // linker matches that behaviour for aliases.
        $aliasPath = $this->tmp . '/nested/parent/alias';
        $result = $this->linker()->link(Path::create($aliasPath), $this->target());

        Assert::same($result->status, LinkStatus::Created);
        Assert::true(\is_dir($this->tmp . '/nested/parent'));
        Assert::true($this->resolvesToTarget($aliasPath));
    }

    private function linker(): SymlinkLinker
    {
        return new SymlinkLinker();
    }

    private function target(): Path
    {
        return Path::create($this->tmp . '/target');
    }

    /**
     * Returns `true` when `$path` (a junction, symlink, or whatever the
     * platform produced) resolves to the same canonical filesystem path
     * as `<tmp>/target`. A behavioural check — independent of
     * `is_link` / `readlink` quirks across PHP builds and platforms.
     */
    private function resolvesToTarget(string $path): bool
    {
        $resolved = \realpath($path);
        $expectedTarget = \realpath($this->tmp . '/target');
        if ($resolved === false || $expectedTarget === false) {
            return false;
        }

        return \DIRECTORY_SEPARATOR === '\\'
            ? \strcasecmp($resolved, $expectedTarget) === 0
            : $resolved === $expectedTarget;
    }
}
