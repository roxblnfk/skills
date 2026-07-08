<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Config\Mapper\ProjectConfigMigrator;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Composer\WithSkillsJson;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance coverage for the legacy trust trio (`trusted`,
 * `trusted-replace`, `local`) → `dependencies` restructure in
 * `skills.json`:
 *
 * - `skills:update` (write-mode) folds the legacy keys into a
 *   `dependencies` block in place, placing it at the first legacy key's
 *   slot and preserving every other key and its order, announces the
 *   rewrite with a `[migrate]` notice, and syncs the trusted donor
 *   exactly as the flat form did.
 * - `skills:show` (read-only) reads the legacy keys, emits the
 *   `[deprecated]` notice, and never touches the file.
 * - A file carrying BOTH `dependencies` and a legacy key is fatal: the
 *   command fails and the file is left untouched for the user to
 *   resolve.
 * - The new form drives the same behaviour the flat form did:
 *   `dependencies.composer.trusted` approves a donor, and
 *   `dependencies.composer == false` disables the vendor walk.
 *
 * Sandbox already has `acme/skills-basic` installed (but not built-in
 * trusted), so approving it via config and syncing its `greeting` skill
 * is the real end-to-end check, not remote fetching.
 */
#[Test]
final class SkillsDependenciesMigrationTest
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
        '$schema' => ProjectConfigMigrator::SCHEMA_URL,
        'target' => '.agents/skills',
        'trusted' => ['acme/skills-basic'],
        'trusted-replace' => false,
        'local' => ['composer' => true],
        'sources' => [],
    ])]
    public function updateRestructuresLegacyTrustTrioInPlaceAndSyncs(): void
    {
        $process = $this->runUpdate();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());

        // The legacy trio folds into one `dependencies` block that takes
        // the slot of the first legacy key (`trusted` here). Every
        // non-legacy key keeps its position; the other legacy keys drop
        // out.
        $skills = $this->readSkillsJson();
        Assert::same(
            \array_keys($skills),
            ['$schema', 'target', 'dependencies', 'sources'],
            'the restructure places `dependencies` at the first legacy key slot, order preserved',
        );
        Assert::false(\array_key_exists('trusted', $skills), 'the legacy trusted key must be folded away');
        Assert::false(\array_key_exists('trusted-replace', $skills), 'the legacy trusted-replace key must be folded away');
        Assert::false(\array_key_exists('local', $skills), 'the legacy local key must be folded away');
        Assert::same(
            $skills['dependencies'],
            [
                'composer' => [
                    'enabled' => true,
                    'trusted' => ['acme/skills-basic'],
                    'trusted-replace' => false,
                ],
            ],
            'the composer entry carries the folded enabled/trusted/trusted-replace values',
        );

        $combined = $process->getOutput() . $process->getErrorOutput();
        Assert::true(
            \str_contains(
                $combined,
                '[migrate] restructured "trusted", "trusted-replace", "local" into "dependencies" in skills.json',
            ),
            'update must announce the in-place restructure naming the keys found. Got: ' . $combined,
        );

        // The trusted Composer donor synced exactly as it would have
        // under the flat form.
        Assert::true(
            \is_file(self::TARGET_DIR . '/greeting/SKILL.md'),
            'the trusted donor must still sync after the restructure',
        );
    }

    #[WithSkillsJson([
        '$schema' => ProjectConfigMigrator::SCHEMA_URL,
        'target' => '.agents/skills',
        'trusted' => ['acme/skills-basic'],
    ])]
    public function showReadsLegacyKeysWithoutRewritingTheFile(): void
    {
        $before = (string) \file_get_contents(self::SKILLS_JSON);

        $process = $this->runShow();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::same(
            \file_get_contents(self::SKILLS_JSON),
            $before,
            'show is read-only and must not rewrite skills.json',
        );

        $combined = $process->getOutput() . $process->getErrorOutput();
        Assert::true(
            \str_contains(
                $combined,
                '[deprecated] config keys "trusted" were replaced by "dependencies"',
            ),
            'show must surface the deprecation notice for the legacy key. Got: ' . $combined,
        );
    }

    #[WithSkillsJson([
        '$schema' => ProjectConfigMigrator::SCHEMA_URL,
        'target' => '.agents/skills',
        'dependencies' => ['composer' => true],
        'trusted' => ['acme/skills-basic'],
    ])]
    public function updateFailsWhenDependenciesAndLegacyTrustedArePresent(): void
    {
        $before = (string) \file_get_contents(self::SKILLS_JSON);

        $process = $this->runUpdate();

        Assert::notSame(
            $process->getExitCode(),
            0,
            'a file mixing dependencies with a legacy key must fail; stderr: ' . $process->getErrorOutput(),
        );
        $combined = $process->getOutput() . $process->getErrorOutput();
        Assert::true(
            \str_contains(
                $combined,
                'both "dependencies" and legacy "trusted" are present; keep "dependencies" only',
            ),
            'the mixing error must name the conflict. Got: ' . $combined,
        );
        Assert::same(
            \file_get_contents(self::SKILLS_JSON),
            $before,
            'the ambiguous file must be left untouched for the user to resolve',
        );
    }

    #[WithSkillsJson([
        '$schema' => ProjectConfigMigrator::SCHEMA_URL,
        'target' => '.agents/skills',
        'dependencies' => [
            'composer' => ['trusted' => ['acme/skills-basic']],
        ],
    ])]
    public function newFormComposerTrustedApprovesAndSyncs(): void
    {
        $before = (string) \file_get_contents(self::SKILLS_JSON);

        $process = $this->runUpdate();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::same(
            \file_get_contents(self::SKILLS_JSON),
            $before,
            'a new-form file needs no migration and must not be rewritten',
        );
        Assert::true(
            \is_file(self::TARGET_DIR . '/greeting/SKILL.md'),
            'dependencies.composer.trusted must approve and sync the donor',
        );
    }

    #[WithSkillsJson([
        '$schema' => ProjectConfigMigrator::SCHEMA_URL,
        'target' => '.agents/skills',
        'dependencies' => ['composer' => false],
    ])]
    public function newFormComposerFalseDisablesTheVendorWalk(): void
    {
        // With the Composer walk off and no sources either, the composite
        // has nothing to do — nothing is written and the runner exits 0
        // with the neutral "no donor providers are active" notice.
        $process = $this->runUpdate();

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::false(
            \is_dir(self::TARGET_DIR),
            'target dir must not be created when the Composer walk is disabled',
        );

        $combined = $process->getOutput() . $process->getErrorOutput();
        Assert::true(
            \str_contains($combined, 'no donor providers are active'),
            'runner must announce the inactive state. Got: ' . $combined,
        );
    }

    private function runUpdate(): Process
    {
        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:update',
            timeout: 60,
            mustSucceed: false,
        );
    }

    private function runShow(): Process
    {
        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:show',
            timeout: 60,
            mustSucceed: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readSkillsJson(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode(
            (string) \file_get_contents(self::SKILLS_JSON),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        return $decoded;
    }
}
