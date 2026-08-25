<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Regression guard for the shared-sandbox contract enforced by
 * {@see \LLM\Skills\Tests\Testo\SandboxIsolation}.
 *
 * Every acceptance test drives the same consumer project, and the
 * commands under test rewrite its config: `skills:init` and
 * `skills:update` move legacy inline `extra.skills` out of
 * `composer.json` and into a new `skills.json`. Because `skills.json`
 * takes precedence over the inline block, a file surviving one test
 * silently reconfigures every test after it — and the damage surfaces
 * far away, as an unrelated test whose `#[WithSandboxExtras]` block
 * appears to be ignored.
 *
 * The two tests below are ordered on purpose: the first performs the
 * migration, the second asserts the sandbox came back. Without the
 * isolation interceptor the second one fails, which is the whole
 * point — a leak has to break the test that guards it, not a random
 * test somewhere down the run.
 */
#[Test]
final class SandboxIsolationTest
{
    private const SKILLS_JSON = Info::PROJECT_DIR . '/skills.json';
    private const COMPOSER_JSON = Info::PROJECT_DIR . '/composer.json';

    #[BeforeTest]
    public function clearTargetDir(): void
    {
        Filesystem::removeRecursive(Info::PROJECT_DIR . '/.agents/skills');
    }

    public function aMigratingCommandRewritesBothConfigFiles(): void
    {
        // Establishes the precondition for the next test: this run
        // creates skills.json and strips the migrated keys out of
        // composer.json, i.e. it leaves the sandbox dirty in exactly
        // the way that used to poison the rest of the suite.
        $process = ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            'skills:init',
            timeout: 60,
            mustSucceed: false,
        );

        Assert::same($process->getExitCode(), 0, 'stderr: ' . $process->getErrorOutput());
        Assert::true(\is_file(self::SKILLS_JSON), 'init must write skills.json');
        Assert::false(
            \array_key_exists('trusted', $this->inlineSkillsBlock()),
            'init must strip the migrated keys out of composer.json',
        );
    }

    public function theFollowingTestSeesAPristineSandbox(): void
    {
        Assert::false(
            \is_file(self::SKILLS_JSON),
            'a skills.json created by the previous test must not survive into this one',
        );
        Assert::same(
            $this->inlineSkillsBlock(),
            ['trusted' => ['acme/skills-basic', 'acme/skills-pro', 'mono/skills-repo']],
            'the previous test migrated composer.json; its inline block must be back',
        );
    }

    /**
     * The sandbox's `extra.skills` block as the next `composer` run
     * would read it.
     *
     * @return array<string, mixed>
     */
    private function inlineSkillsBlock(): array
    {
        /** @var array{extra?: array{skills?: array<string, mixed>}} $decoded */
        $decoded = \json_decode(
            (string) \file_get_contents(self::COMPOSER_JSON),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        return $decoded['extra']['skills'] ?? [];
    }
}
