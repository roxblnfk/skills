<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Composer\WithSkillsJson;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance coverage for the `local.composer` toggle in `skills.json`:
 *
 * - `local.composer == false` disables the Composer donor provider
 *   even when a valid `composer.json` is around — the runner falls
 *   through to its `no donor providers are active` notice and writes
 *   nothing.
 * - `local.composer == true` (or absent) preserves the pre-`local`
 *   behaviour: skills get synced from installed donor packages.
 *
 * Sandbox already has `acme/skills-basic` installed and trusted — the
 * test exercises the toggle alone, keeping every other knob at default.
 */
#[Test]
final class SkillsLocalToggleTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';
    private const SKILLS_JSON = Info::PROJECT_DIR . '/skills.json';

    #[BeforeTest]
    public function clearSyncTarget(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
    }

    #[AfterTest]
    public function removeSkillsJson(): void
    {
        if (\is_file(self::SKILLS_JSON)) {
            @\unlink(self::SKILLS_JSON);
        }
    }

    #[WithSkillsJson([
        'trusted' => ['acme/skills-basic'],
        'local' => ['composer' => false],
    ])]
    public function composerLocalToggleOffSkipsAllInstalledDonors(): void
    {
        // With Composer discovery off and no remote entries either, the
        // composite has nothing to do — nothing is written, the runner
        // exits 0 with the neutral "nothing to sync" notice.
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::false(
            \is_dir(self::TARGET_DIR),
            'target dir must not be created when no provider is active',
        );

        $combined = $process->getOutput() . $process->getErrorOutput();
        Assert::true(
            \str_contains($combined, 'no donor providers are active'),
            'runner must announce the inactive state. Got: ' . $combined,
        );
    }

    #[WithSkillsJson([
        'trusted' => ['acme/skills-basic'],
        'local' => ['composer' => true],
    ])]
    public function composerLocalToggleOnKeepsTransitiveDiscovery(): void
    {
        // Sanity check: explicit `local.composer: true` is the
        // pre-`local` default and must produce a normal sync.
        $process = $this->runSync();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(
            \is_file(self::TARGET_DIR . '/greeting/SKILL.md'),
            'skills must sync when local.composer is true',
        );
    }

    private function runSync(string ...$args): Process
    {
        $command = 'skills:update';
        if ($args !== []) {
            $command .= ' ' . \implode(' ', $args);
        }

        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            $command,
            timeout: 60,
            mustSucceed: false,
        );
    }
}
