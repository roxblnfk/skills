<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Feature;

use Composer\IO\BufferIO;
use Internal\Path;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Discovery\Provider\Source\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Source\RemoteDonorRef;
use LLM\Skills\Discovery\Provider\Source\RemoteFetcher;
use LLM\Skills\Discovery\Provider\Source\SkillsJsonDonorRefSource;
use LLM\Skills\Discovery\Provider\Source\SourceProvider;
use LLM\Skills\Sync\SyncRunner;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Console\Command\Command;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Covers the interactive half of `skills:update --clean`.
 *
 * Acceptance tests cannot reach it: {@see \LLM\Skills\Tests\Testo\Composer\ComposerRunner}
 * appends `--no-interaction` to every command, and a subprocess without a TTY
 * would report itself non-interactive anyway. So the prompt is driven here
 * instead — the whole {@see SyncRunner} pipeline over a real filesystem, with
 * Composer's {@see BufferIO} scripting the answer.
 */
#[Test]
#[Covers(SyncRunner::class)]
final class CleanSyncConfirmationTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-clean-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);

        \file_put_contents($this->tmp . '/skills.json', \json_encode([
            'target' => '.agents/skills',
            'sources' => [
                ['from' => 'dir', 'path' => './local-skills'],
            ],
        ], \JSON_THROW_ON_ERROR));

        $this->writeDonorSkill('hello');
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function decliningThePromptLeavesTheTargetExactlyAsItWas(): void
    {
        $this->run(clean: false, interactive: false);
        $this->plantLocalFile('hello/notes.md', 'local notes');

        $io = $this->ioAnswering(['n']);
        $exit = $this->run(clean: true, interactive: true, io: $io);

        Assert::same($exit, Command::SUCCESS, 'declining is the user’s choice, not a failure');
        Assert::same(
            \file_get_contents($this->targetPath('hello/notes.md')),
            'local notes',
            'a declined --clean must not delete, and must not fall through to a merge either',
        );
        Assert::true(\str_contains($io->getOutput(), 'aborted'));
    }

    public function thePromptNamesEverySkillItWouldDelete(): void
    {
        $this->run(clean: false, interactive: false);

        $io = $this->ioAnswering(['n']);
        $this->run(clean: true, interactive: true, io: $io);

        $output = $io->getOutput();
        Assert::true(
            \str_contains($output, 'will delete'),
            'the prompt must state what is at stake. Got: ' . $output,
        );
        Assert::true(\str_contains($output, 'hello'), 'each skill is named. Got: ' . $output);
    }

    public function acceptingThePromptPerformsTheWipe(): void
    {
        $this->run(clean: false, interactive: false);
        $this->plantLocalFile('hello/notes.md', 'local notes');

        $io = $this->ioAnswering(['y']);
        $exit = $this->run(clean: true, interactive: true, io: $io);

        Assert::same($exit, Command::SUCCESS);
        Assert::false(\is_file($this->targetPath('hello/notes.md')));
        Assert::true(\is_file($this->targetPath('hello/SKILL.md')), 'the donor copy is written back');
    }

    public function anEmptyAnswerDefaultsToNo(): void
    {
        // Enter on a destructive prompt must be the safe answer.
        $this->run(clean: false, interactive: false);
        $this->plantLocalFile('hello/notes.md', 'local notes');

        $io = $this->ioAnswering(['']);
        $this->run(clean: true, interactive: true, io: $io);

        Assert::true(\is_file($this->targetPath('hello/notes.md')));
    }

    public function aNonInteractiveRunWipesWithoutAsking(): void
    {
        // CI and the `post-update-cmd` hook pass interactive=false; a prompt
        // there would hang the build.
        $this->run(clean: false, interactive: false);
        $this->plantLocalFile('hello/notes.md', 'local notes');

        $io = $this->ioAnswering([]);
        $exit = $this->run(clean: true, interactive: false, io: $io);

        Assert::same($exit, Command::SUCCESS);
        Assert::false(\is_file($this->targetPath('hello/notes.md')));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function run(bool $clean, bool $interactive, ?BufferIO $io = null): int
    {
        $projectRoot = Path::create($this->tmp);
        $provider = new SourceProvider(
            new SkillsJsonDonorRefSource(new HostAdapterRegistry()),
            self::neverFetch(),
        );

        return (new SyncRunner())->run(
            $projectRoot,
            $provider,
            null,
            $io ?? new BufferIO(),
            new SyncOptions(
                packageFilters: [],
                extraTrusted: [],
                targetOverride: null,
                interactive: $interactive,
                autoMigrate: false,
                clean: $clean,
            ),
        );
    }

    /**
     * @param list<string> $answers
     */
    private function ioAnswering(array $answers): BufferIO
    {
        $io = new BufferIO();
        $io->setUserInputs($answers);

        return $io;
    }

    /**
     * @param non-empty-string $name
     */
    private function writeDonorSkill(string $name): void
    {
        $dir = $this->tmp . '/local-skills/skills/' . $name;
        \mkdir($dir, 0o777, true);
        \file_put_contents($dir . '/SKILL.md', "---\nname: " . $name . "\n---\nbody");
    }

    /**
     * Drop a file into an already-synced skill, standing in for something the
     * donor does not ship — a user's own note, or a file dropped upstream.
     *
     * @param non-empty-string $relative
     */
    private function plantLocalFile(string $relative, string $contents): void
    {
        \file_put_contents($this->targetPath($relative), $contents);
    }

    /**
     * @param non-empty-string $relative
     */
    private function targetPath(string $relative): string
    {
        return $this->tmp . '/.agents/skills/' . $relative;
    }

    private static function neverFetch(): RemoteFetcher
    {
        return new class implements RemoteFetcher {
            #[\Override]
            public function fetch(RemoteDonorRef $ref): Path
            {
                throw new \LogicException('dir refs must not reach the fetcher');
            }
        };
    }
}
