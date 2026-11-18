<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use Internal\Path;
use LLM\Skills\Tests\Testo\Composer\ComposerRunner;
use LLM\Skills\Tests\Testo\Composer\WithSandboxExtras;
use LLM\Skills\Tests\Testo\Composer\WithSkillsJson;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Acceptance tests for the personal `skills.local.json` layered over the
 * shared configuration: what a sync installs under the merged config,
 * the `[local]` line every run prints, the `[local]` marks in
 * `skills:show`, the errors carrying the local file's name, and the
 * guarantee that neither migrations nor `skills:add --local` write
 * merged values into `skills.json`, and that `skills:add` accounts for a
 * local entry overriding the one it writes.
 *
 * Runs against the standard sandbox at `tests/Sandbox/project`, whose
 * `composer.json` trusts `acme/skills-basic` (`greeting`, `code-review`)
 * inline, and whose `local-skills/` directory holds the bare skills
 * `dir-hello` and `dir-extra`.
 */
#[Test]
final class SkillsLocalConfigTest
{
    private const DEFAULT_TARGET = Info::PROJECT_DIR . '/.agents/skills';
    private const LOCAL_TARGET = Info::PROJECT_DIR . '/local-target/skills';
    private const SKILLS_JSON = Info::PROJECT_DIR . '/skills.json';
    private const LOCAL_JSON = Info::PROJECT_DIR . '/skills.local.json';
    private const COMPOSER_JSON = Info::PROJECT_DIR . '/composer.json';
    private const LOCAL_ALIAS = Info::PROJECT_DIR . '/.claude/skills-local-alias';

    private const TRUST_BASIC = ['dependencies' => ['composer' => ['trusted' => ['acme/skills-basic']]]];

    /**
     * Shared trust limited to `acme/skills-basic`: neither the built-in list nor
     * direct-dependency trust approves another sandbox donor.
     */
    private const TRUST_ONLY_BASIC = ['dependencies' => ['composer' => [
        'trusted' => ['acme/skills-basic'],
        'trusted-replace' => true,
    ]]];

    private ?string $originalComposerJson = null;

    #[BeforeTest]
    public function snapshotAndClear(): void
    {
        $raw = \file_get_contents(self::COMPOSER_JSON);
        $this->originalComposerJson = $raw === false ? null : $raw;

        Filesystem::removeRecursive(self::DEFAULT_TARGET);
        Filesystem::removeRecursive(Info::PROJECT_DIR . '/local-target');
        Filesystem::removeRecursive(self::LOCAL_ALIAS);
    }

    #[AfterTest]
    public function restore(): void
    {
        if ($this->originalComposerJson !== null) {
            \file_put_contents(self::COMPOSER_JSON, $this->originalComposerJson);
        }
        foreach ([self::SKILLS_JSON, self::LOCAL_JSON] as $file) {
            if (\is_file($file)) {
                @\unlink($file);
            }
        }
        Filesystem::removeRecursive(self::DEFAULT_TARGET);
        Filesystem::removeRecursive(Info::PROJECT_DIR . '/local-target');
        Filesystem::removeRecursive(self::LOCAL_ALIAS);
    }

    #[WithSkillsJson(['target' => '.agents/skills', ...self::TRUST_BASIC])]
    public function aLocalTargetOverridesTheSharedOne(): void
    {
        $shared = (string) \file_get_contents(self::SKILLS_JSON);
        $this->writeLocal(['target' => 'local-target/skills']);

        $process = $this->run('skills:update');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(\is_file(self::LOCAL_TARGET . '/greeting/SKILL.md'), $this->diagnostics($process));
        Assert::false(\is_dir(self::DEFAULT_TARGET . '/greeting'), 'the shared target must not be used');
        Assert::true(
            \str_contains($process->getOutput(), '[local] skills.local.json: target=local-target/skills'),
            $this->diagnostics($process),
        );
        Assert::same((string) \file_get_contents(self::SKILLS_JSON), $shared, 'skills.json must stay as it was');
    }

    #[WithSkillsJson(self::TRUST_BASIC)]
    public function aLocalSourceAddsADonor(): void
    {
        $this->writeLocal(['sources' => [['from' => 'dir', 'path' => './local-skills']]]);

        $process = $this->run('skills:update');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(\is_file(self::DEFAULT_TARGET . '/greeting/SKILL.md'), 'the shared trust still applies');
        Assert::true(\is_file(self::DEFAULT_TARGET . '/dir-hello/SKILL.md'), $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getOutput(), '[local] skills.local.json: sources +1'),
            $this->diagnostics($process),
        );
    }

    #[WithSkillsJson(['sources' => [['from' => 'dir', 'path' => './local-skills']]])]
    public function anEmptyLocalAllowlistDisablesASharedSource(): void
    {
        $this->writeLocal(['sources' => [['from' => 'dir', 'path' => './local-skills', 'skills' => []]]]);

        $process = $this->run('skills:update');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::false(\is_dir(self::DEFAULT_TARGET . '/dir-hello'), $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getOutput(), '[local] skills.local.json: sources ~1'),
            $this->diagnostics($process),
        );
    }

    #[WithSandboxExtras([])]
    public function aLocalFileWithoutSkillsJsonActsAsTheMainFile(): void
    {
        $this->writeLocal(['target' => 'local-target/skills', ...self::TRUST_BASIC]);

        $process = $this->run('skills:update');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(\is_file(self::LOCAL_TARGET . '/greeting/SKILL.md'), $this->diagnostics($process));
        Assert::false(\is_file(self::SKILLS_JSON), 'no skills.json may be created');
    }

    public function migrationWritesOnlyTheSharedValues(): void
    {
        // The inline `trusted` block moves into a new skills.json; the
        // local target applies to the run but never reaches that file.
        $local = $this->writeLocal(['target' => 'local-target/skills']);

        $process = $this->run('skills:update');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(\str_contains($process->getOutput(), '[migrate]'), $this->diagnostics($process));
        Assert::true(\is_file(self::LOCAL_TARGET . '/greeting/SKILL.md'), $this->diagnostics($process));

        /** @var array<string, mixed> $written */
        $written = \json_decode((string) \file_get_contents(self::SKILLS_JSON), true, flags: \JSON_THROW_ON_ERROR);
        Assert::false(\array_key_exists('target', $written), 'the local target must not leak into skills.json');
        Assert::true(\array_key_exists('dependencies', $written));
        Assert::same((string) \file_get_contents(self::LOCAL_JSON), $local, 'skills.local.json must stay as it was');
    }

    #[WithSkillsJson(['target' => '.agents/skills', ...self::TRUST_BASIC])]
    public function showMarksTheValuesTheLocalFileSourced(): void
    {
        $this->writeLocal(['target' => 'local-target/skills']);

        $process = $this->run('skills:show');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        $output = $process->getOutput();
        Assert::true(
            \str_contains($output, '[local] skills.local.json: target=local-target/skills'),
            $this->diagnostics($process),
        );
        $targetLine = '';
        foreach (\explode("\n", $output) as $line) {
            if (\str_starts_with($line, 'Target:')) {
                $targetLine = $line;
            }
        }
        Assert::true(\str_contains($targetLine, 'local-target'), 'Got: ' . $targetLine);
        Assert::true(\str_ends_with(\rtrim($targetLine), '[local]'), 'Got: ' . $targetLine);
    }

    #[WithSkillsJson(self::TRUST_BASIC)]
    public function aMalformedLocalFileFailsWithItsName(): void
    {
        $this->writeLocal(['discovery' => 'yes']);

        $process = $this->run('skills:update');

        Assert::notSame($process->getExitCode(), 0);
        Assert::true(
            \str_contains($process->getErrorOutput(), 'skills.local.json: discovery must be a boolean'),
            $this->diagnostics($process),
        );
    }

    #[WithSkillsJson(self::TRUST_BASIC)]
    public function aDeprecatedKeyInTheLocalFileIsRejected(): void
    {
        $this->writeLocal(['trusted' => ['acme/skills-pro']]);

        $process = $this->run('skills:update');

        Assert::notSame($process->getExitCode(), 0);
        Assert::true(
            \str_contains($process->getErrorOutput(), 'skills.local.json: deprecated key "trusted" is not accepted here'),
            $this->diagnostics($process),
        );
    }

    public function addLocalWritesTheEntryToTheLocalFile(): void
    {
        $shared = \json_encode(self::TRUST_BASIC, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR) . "\n";
        \file_put_contents(self::SKILLS_JSON, $shared);

        $process = $this->run('skills:add ./local-skills --local');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::same((string) \file_get_contents(self::SKILLS_JSON), $shared, 'skills.json must stay as it was');

        /** @var array<string, mixed> $local */
        $local = \json_decode((string) \file_get_contents(self::LOCAL_JSON), true, flags: \JSON_THROW_ON_ERROR);
        Assert::same($local['sources'] ?? null, [['from' => 'dir', 'path' => './local-skills']]);
        Assert::true(
            \str_contains($process->getOutput(), 'registered dir:./local-skills in skills.local.json'),
            $this->diagnostics($process),
        );
        Assert::true(\is_file(self::DEFAULT_TARGET . '/dir-hello/SKILL.md'), $this->diagnostics($process));
    }

    #[WithSkillsJson(self::TRUST_ONLY_BASIC)]
    public function localTrustIsAddedToTheSharedTrust(): void
    {
        $this->writeLocal(['dependencies' => ['composer' => ['trusted' => ['acme/skills-pro']]]]);

        $process = $this->run('skills:update');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(\is_file(self::DEFAULT_TARGET . '/greeting/SKILL.md'), $this->diagnostics($process));
        Assert::true(\is_file(self::DEFAULT_TARGET . '/refactor/SKILL.md'), $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getOutput(), '[local] skills.local.json: dependencies.composer.trusted +1'),
            $this->diagnostics($process),
        );
    }

    #[WithSkillsJson(self::TRUST_ONLY_BASIC)]
    public function aLocalBoolDependencyTurnsTheManagerOffAndKeepsItsTrust(): void
    {
        $this->writeLocal(['dependencies' => ['composer' => false]]);

        $process = $this->run('skills:update');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::false(\is_dir(self::DEFAULT_TARGET . '/greeting'), $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getOutput(), '[local] skills.local.json: dependencies.composer.enabled=false'),
            $this->diagnostics($process),
        );
    }

    #[WithSkillsJson(self::TRUST_ONLY_BASIC)]
    public function anEmptyLocalDependenciesObjectKeepsTheSharedTrust(): void
    {
        $this->writeLocal(['dependencies' => new \stdClass()]);

        $process = $this->run('skills:update');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(\is_file(self::DEFAULT_TARGET . '/greeting/SKILL.md'), $this->diagnostics($process));
        Assert::false(\is_dir(self::DEFAULT_TARGET . '/refactor'), 'trusted-replace must still hold');
        Assert::true(
            \str_contains($process->getOutput(), '[local] skills.local.json: no effective changes'),
            $this->diagnostics($process),
        );
    }

    #[WithSkillsJson(self::TRUST_BASIC)]
    public function aLocalAliasIsMirroredAndMarkedInShow(): void
    {
        $this->writeLocal(['aliases' => ['.claude/skills-local-alias']]);

        $update = $this->run('skills:update');

        Assert::same($update->getExitCode(), 0, $this->diagnostics($update));
        Assert::true(\is_file(self::LOCAL_ALIAS . '/greeting/SKILL.md'), $this->diagnostics($update));

        $show = $this->run('skills:show');

        Assert::same($show->getExitCode(), 0, $this->diagnostics($show));
        $aliasesLine = '';
        foreach (\explode("\n", $show->getOutput()) as $line) {
            if (\str_starts_with($line, 'Aliases:')) {
                $aliasesLine = $line;
            }
        }
        Assert::true(\str_contains($aliasesLine, 'skills-local-alias [local]'), 'Got: ' . $aliasesLine);
    }

    #[WithSkillsJson(self::TRUST_BASIC)]
    public function showNamesTheLocalFileForADonorItDeclares(): void
    {
        $this->writeLocal(['sources' => [['from' => 'dir', 'path' => './local-skills']]]);

        $process = $this->run('skills:show');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getOutput(), '[declared in skills.local.json]'),
            $this->diagnostics($process),
        );
        Assert::false(
            \str_contains($process->getOutput(), '[declared in skills.json]'),
            $this->diagnostics($process),
        );
    }

    #[WithSkillsJson(self::TRUST_BASIC)]
    public function localAutoSyncFalseStopsThePostInstallHook(): void
    {
        $this->writeLocal(['auto-sync' => false]);

        $process = $this->run('run-script post-install-cmd');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::false(\is_dir(self::DEFAULT_TARGET), $this->diagnostics($process));
    }

    #[WithSkillsJson(['sources' => [['from' => 'dir', 'path' => './local-skills']]])]
    public function aConflictingAddLocalRestoresTheLocalFile(): void
    {
        // `local-skills-case` ships `Dir-Hello`, a namesake of the shared
        // `local-skills` donor's `dir-hello` on a case-insensitive target.
        $shared = (string) \file_get_contents(self::SKILLS_JSON);
        $local = $this->writeLocal(['vendor-sources' => true]);
        Assert::same($this->run('skills:update')->getExitCode(), 0);

        $process = $this->run('skills:add ./local-skills-case --local');

        Assert::notSame($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getErrorOutput(), 'skills.local.json is as it was'),
            $this->diagnostics($process),
        );
        Assert::same((string) \file_get_contents(self::LOCAL_JSON), $local, $this->diagnostics($process));
        Assert::same((string) \file_get_contents(self::SKILLS_JSON), $shared, $this->diagnostics($process));
    }

    #[WithSkillsJson(self::TRUST_BASIC)]
    public function anAddTheLocalFileOverridesWarnsThatTheLocalEntryWins(): void
    {
        // The local entry keeps the donor with nothing pulled; the shared
        // entry is written, but the sync follows the local one.
        $this->writeLocal(['sources' => [['from' => 'dir', 'path' => './local-skills', 'skills' => []]]]);

        $process = $this->run('skills:add ./local-skills');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \str_contains(
                $process->getErrorOutput(),
                'skills.local.json declares dir:./local-skills too; its entry replaces the one written to skills.json',
            ),
            $this->diagnostics($process),
        );
        Assert::false(\is_dir(self::DEFAULT_TARGET . '/dir-hello'), $this->diagnostics($process));
    }

    #[WithSkillsJson(self::TRUST_BASIC)]
    public function anAddTheLocalFileNarrowsToNothingIsRefused(): void
    {
        // The local allowlist names no skill the donor ships, so the entry
        // in effect would copy nothing.
        $shared = (string) \file_get_contents(self::SKILLS_JSON);
        $this->writeLocal(['sources' => [['from' => 'dir', 'path' => './local-skills', 'skills' => ['gone']]]]);

        $process = $this->run('skills:add ./local-skills');

        Assert::notSame($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getErrorOutput(), 'skills.local.json declares dir:./local-skills too'),
            $this->diagnostics($process),
        );
        Assert::same((string) \file_get_contents(self::SKILLS_JSON), $shared, 'a refused add leaves skills.json as it was');
    }

    #[WithSandboxExtras(['target' => 'local-target/skills'])]
    public function addLocalWithoutSkillsJsonKeepsTheInlineTarget(): void
    {
        $process = $this->run('skills:add ./local-skills --local');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::false(\is_file(self::SKILLS_JSON), 'no skills.json may be created');
        Assert::true(\is_file(self::LOCAL_TARGET . '/dir-hello/SKILL.md'), $this->diagnostics($process));
    }

    #[WithSandboxExtras(['target' => 'local-target/skills'])]
    public function aLocalSourceOverAnInlineBaseIsInstalled(): void
    {
        // The alias equals the default target only, so the local file is
        // valid against the inline target alone; the post-install hook
        // reads the inline block without migrating it.
        $this->writeLocal([
            'aliases' => ['.agents/skills'],
            'sources' => [['from' => 'dir', 'path' => './local-skills']],
        ]);

        $process = $this->run('run-script post-install-cmd');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(\is_file(self::LOCAL_TARGET . '/dir-hello/SKILL.md'), $this->diagnostics($process));
        Assert::true(\is_file(self::DEFAULT_TARGET . '/dir-hello/SKILL.md'), 'the alias must mirror the target');
        Assert::false(\is_file(self::SKILLS_JSON), 'no skills.json may be created');
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic'],
        'sources' => [['from' => 'dir', 'path' => './local-skills']],
    ])]
    public function theInlineDonorAddressBookIsNotFetched(): void
    {
        // The post-install hook reads the inline block without migrating it.
        $this->writeLocal(['vendor-sources' => true]);

        $process = $this->run('run-script post-install-cmd');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(\is_file(self::DEFAULT_TARGET . '/greeting/SKILL.md'), $this->diagnostics($process));
        Assert::false(\is_dir(self::DEFAULT_TARGET . '/dir-hello'), $this->diagnostics($process));
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic'],
        'remote' => [['from' => 'dir', 'path' => './local-skills']],
    ])]
    public function aLegacyInlineRemoteIsNotFetchedBeforeMigration(): void
    {
        // The post-install hook reads the inline block without migrating it;
        // the legacy pull list waits for a write-mode run to move it.
        $process = $this->run('run-script post-install-cmd');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(\is_file(self::DEFAULT_TARGET . '/greeting/SKILL.md'), $this->diagnostics($process));
        Assert::false(\is_dir(self::DEFAULT_TARGET . '/dir-hello'), $this->diagnostics($process));
        Assert::false(\is_file(self::SKILLS_JSON), 'no skills.json may be created');
        Assert::true(
            \str_contains(
                $process->getErrorOutput(),
                '[deprecated] the inline extra.skills.remote list is not fetched; '
                . 'skills:update moves it into "sources" in skills.json',
            ),
            $this->diagnostics($process),
        );
        Assert::false(\str_contains($process->getErrorOutput(), 'was renamed to'), $this->diagnostics($process));
    }

    #[WithSkillsJson(['sources' => [['from' => 'dir', 'path' => './local-skills']]])]
    public function aLocalDirEntrySpelledDifferentlyReplacesTheSharedOne(): void
    {
        $this->writeLocal(['sources' => [['from' => 'dir', 'path' => 'local-skills', 'skills' => ['dir-hello']]]]);

        $process = $this->run('skills:update');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getOutput(), '[local] skills.local.json: sources ~1'),
            $this->diagnostics($process),
        );
        Assert::true(\is_file(self::DEFAULT_TARGET . '/dir-hello/SKILL.md'), $this->diagnostics($process));
        Assert::false(\is_dir(self::DEFAULT_TARGET . '/dir-extra'), 'the local allowlist must apply');
    }

    #[WithSandboxExtras([
        'trusted' => ['acme/skills-basic'],
        'sources' => [['from' => 'dir', 'path' => './local-skills']],
    ])]
    public function migrationLeavesTheInlineDonorAddressBookInComposerJson(): void
    {
        $process = $this->run('skills:update');

        Assert::same($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(\str_contains($process->getOutput(), '[migrate]'), $this->diagnostics($process));
        Assert::false(\is_dir(self::DEFAULT_TARGET . '/dir-hello'), $this->diagnostics($process));

        /** @var array<string, mixed> $written */
        $written = \json_decode((string) \file_get_contents(self::SKILLS_JSON), true, flags: \JSON_THROW_ON_ERROR);
        Assert::false(\array_key_exists('sources', $written), 'the address book must not reach skills.json');

        /** @var array{extra?: array{skills?: array<string, mixed>}} $composer */
        $composer = \json_decode((string) \file_get_contents(self::COMPOSER_JSON), true, flags: \JSON_THROW_ON_ERROR);
        Assert::same(
            $composer['extra']['skills'] ?? null,
            ['sources' => [['from' => 'dir', 'path' => './local-skills']]],
            'the address book stays in composer.json',
        );
    }

    #[WithSkillsJson(['aliases' => ['.claude/skills-local-alias'], ...self::TRUST_BASIC])]
    public function aLocalAliasSpellingASharedAliasDifferentlyNamesBothFiles(): void
    {
        $this->writeLocal(['aliases' => ['x/../.claude/skills-local-alias']]);

        $process = $this->run('skills:update');

        Assert::notSame($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getErrorOutput(), 'skills.local.json: alias "x/../.claude/skills-local-alias"'),
            $this->diagnostics($process),
        );
        Assert::true(
            \str_contains($process->getErrorOutput(), 'alias ".claude/skills-local-alias" of skills.json already mirrors'),
            $this->diagnostics($process),
        );
    }

    #[WithSkillsJson(['aliases' => ['.claude/skills-local-alias'], ...self::TRUST_BASIC])]
    public function aLocalTargetSpellingASharedAliasDifferentlyNamesBothFiles(): void
    {
        $this->writeLocal(['target' => 'x/../.claude/skills-local-alias']);

        $process = $this->run('skills:update');

        Assert::notSame($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getErrorOutput(), 'skills.json: alias ".claude/skills-local-alias" resolves to the target path'),
            $this->diagnostics($process),
        );
        Assert::true(
            \str_contains($process->getErrorOutput(), 'set in skills.local.json'),
            $this->diagnostics($process),
        );
    }

    #[WithSkillsJson(['aliases' => ['.claude/skills-local-alias'], ...self::TRUST_BASIC])]
    public function aNullInTheLocalFileIsRejected(): void
    {
        $this->writeLocal(['aliases' => null]);

        $process = $this->run('skills:update');

        Assert::notSame($process->getExitCode(), 0, $this->diagnostics($process));
        Assert::true(
            \str_contains($process->getErrorOutput(), 'skills.local.json: aliases must not be null'),
            $this->diagnostics($process),
        );
        Assert::false(\is_dir(self::DEFAULT_TARGET), $this->diagnostics($process));
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return string the written file content
     */
    private function writeLocal(array $content): string
    {
        $json = \json_encode($content, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
        \file_put_contents(self::LOCAL_JSON, $json);

        return $json;
    }

    private function run(string $command): Process
    {
        return ComposerRunner::run(
            Path::create(Info::PROJECT_DIR),
            $command,
            timeout: 60,
            mustSucceed: false,
        );
    }

    private function diagnostics(Process $process): string
    {
        return "stdout:\n" . $process->getOutput() . "\nstderr:\n" . $process->getErrorOutput();
    }
}
