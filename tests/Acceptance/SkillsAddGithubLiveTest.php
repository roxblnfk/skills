<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Filesystem;
use LLM\Skills\Unpacker\UnpackerFactory;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Live network smoke test for `skills:add` against a real GitHub
 * donor — `php-testo/testo` at tag `0.10.11`.
 *
 * **Off by default.** The suite runs only when the environment
 * variable `SKILLS_LIVE_TESTS=1` is set, because it depends on:
 *
 * - network reachability of `api.github.com` and
 *   `codeload.github.com` (zipball endpoint);
 * - the upstream tag `0.10.11` continuing to exist and continuing
 *   to ship a Composer-shaped `extra.skills.source` block;
 * - the absence of GitHub rate-limit throttling on the runner's IP.
 *
 * Without the env flag the test reports as Skipped (via testo's
 * {@see SkipTest}) — keeps the default CI green and free of
 * network surprises. Set the flag locally (or in a manually-
 * triggered job) to exercise the real path:
 *
 *     SKILLS_LIVE_TESTS=1 composer test
 *
 * What the test asserts:
 *
 * 1. `composer skills:add php-testo/testo --from=github --ref=0.10.11`
 *    exits cleanly in the sandbox project.
 * 2. `skills.json` ends up with the expected remote entry.
 * 3. At least one of the upstream `testo-*` skill directories
 *    landed under the sandbox's `target`.
 */
#[Test]
final class SkillsAddGithubLiveTest
{
    private const TARGET_DIR = Info::PROJECT_DIR . '/.agents/skills';
    private const SKILLS_JSON = Info::PROJECT_DIR . '/skills.json';

    /**
     * The upstream donor used by the live test. Pinned to a tag so
     * the assertion shape stays stable: the test does NOT just check
     * "some skills landed" but checks for a directory name that
     * exists at exactly this tag.
     */
    private const REPO = 'php-testo/testo';

    private const REF = '0.10.11';

    /**
     * One known-shipped skill name at REF. Pinning to a specific
     * directory keeps the assertion robust against changes in
     * unrelated upstream skills — if `testo-write-tests` is ever
     * renamed in a future tag, bump REF + this constant together.
     */
    private const EXPECTED_SKILL = 'testo-write-tests';

    #[BeforeTest]
    public static function resetTarget(): void
    {
        if (\getenv('SKILLS_LIVE_TESTS') !== '1') {
            // Cleanup is the test's own concern; nothing to wipe when
            // the body is going to skip anyway. The skip itself must
            // escape from the test method — throwing here would turn
            // into a pipeline Aborted, not a Skipped verdict.
            return;
        }

        Filesystem::removeRecursive(self::TARGET_DIR);
        if (\is_file(self::SKILLS_JSON)) {
            @\unlink(self::SKILLS_JSON);
        }
    }

    #[AfterTest]
    public static function cleanup(): void
    {
        Filesystem::removeRecursive(self::TARGET_DIR);
        if (\is_file(self::SKILLS_JSON)) {
            @\unlink(self::SKILLS_JSON);
        }
    }

    public function liveSkillsAddPullsRealDonorIntoTarget(): void
    {
        if (\getenv('SKILLS_LIVE_TESTS') !== '1') {
            throw new SkipTest('SKILLS_LIVE_TESTS=1 not set — live network test skipped');
        }

        // The fetcher auto-selects between ext-zip and a CLI extractor
        // (unzip / 7z / 7zz / 7za). If absolutely none of those is on
        // the box, even the live path cannot work — skip rather than
        // surface a confusing "no archive extractor" failure here.
        if ((new UnpackerFactory())->detect() === null) {
            throw new SkipTest(
                'no archive extractor available — install ext-zip or one of: '
                . \implode(', ', UnpackerFactory::reportedCliTools()),
            );
        }

        $process = $this->runAdd(self::REPO, self::REF);

        Assert::same(
            $process->getExitCode(),
            0,
            'skills:add failed. stdout: ' . $process->getOutput()
            . "\nstderr: " . $process->getErrorOutput(),
        );

        Assert::true(
            \is_file(self::SKILLS_JSON),
            'skills:add must have created skills.json at ' . self::SKILLS_JSON,
        );

        /** @var array<string, mixed> $payload */
        $payload = \json_decode(
            (string) \file_get_contents(self::SKILLS_JSON),
            associative: true,
            flags: \JSON_THROW_ON_ERROR,
        );
        /** @var list<array<string, mixed>> $remote */
        $remote = (array) ($payload['remote'] ?? []);
        Assert::count($remote, 1, 'exactly one remote entry must be registered');
        Assert::same($remote[0]['from'] ?? null, 'github');
        Assert::same($remote[0]['package'] ?? null, self::REPO);
        Assert::same($remote[0]['ref'] ?? null, self::REF);

        // The downstream sync ran as part of `skills:add` (no --no-sync
        // was passed), so the donor's skill directories must already
        // be sitting in the target.
        $expected = self::TARGET_DIR . '/' . self::EXPECTED_SKILL;
        Assert::true(
            \is_dir($expected),
            'expected skill directory not found: ' . $expected
            . '. Tree of target: ' . $this->describeTargetTree(),
        );
    }

    private function runAdd(string $repo, string $ref): Process
    {
        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            \sprintf('skills:add %s --from=github --ref=%s', $repo, $ref),
            timeout: 120,
            mustSucceed: false,
        );
    }

    /**
     * Cheap diagnostic for failed assertions — lists the immediate
     * children of the target directory so the developer can see what
     * actually landed when the expected skill was not found.
     */
    private function describeTargetTree(): string
    {
        if (!\is_dir(self::TARGET_DIR)) {
            return '<target does not exist>';
        }
        $entries = @\scandir(self::TARGET_DIR);
        if ($entries === false) {
            return '<target unreadable>';
        }
        $names = \array_values(\array_filter(
            $entries,
            static fn($e) => $e !== '.' && $e !== '..',
        ));
        return $names === [] ? '<empty>' : \implode(', ', $names);
    }
}
