<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Add;

use Composer\IO\BufferIO;
use Internal\Path;
use LLM\Skills\Add\AddRunner;
use LLM\Skills\Config\AddOptions;
use LLM\Skills\Config\RemoteEntry;
use LLM\Skills\Discovery\Provider\ProviderId;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapter;
use LLM\Skills\Discovery\Provider\Remote\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Remote\Adapter\ParsedAddInput;
use LLM\Skills\Discovery\Provider\Remote\Adapter\RemoteResolveException;
use LLM\Skills\Discovery\Provider\Remote\RemoteDonorRef;
use LLM\Skills\Discovery\Provider\Remote\RemoteFetchException;
use LLM\Skills\Discovery\Provider\Remote\RemoteFetcher;
use LLM\Skills\Tests\Testo\Filesystem;
use Symfony\Component\Console\Command\Command;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Unit coverage for the `skills:add` orchestration.
 *
 * Every external collaborator is a controlled test-double:
 *
 * - {@see HostAdapterRegistry} → carries one
 *   {@see StubAdapter} configured per-test for parse / resolve
 *   behaviour.
 * - {@see RemoteFetcher} → returns a fresh tmp dir with a controllable
 *   `composer.json` shape, or throws {@see RemoteFetchException}.
 * - {@see SkillsJsonWriter} → real, writes to the same tmp dir so
 *   tests can inspect the resulting skills.json.
 *
 * The runner's branches we pin down:
 *
 * - Adapter selection: explicit `--from`, unknown `--from`, infer
 *   from URL host, shorthand without `--from` is rejected.
 * - Parse / resolve / fetch errors each map to distinct exit codes
 *   and surface the underlying message via {@see BufferIO}.
 * - Archive validation: missing `composer.json`, invalid JSON,
 *   missing `extra.skills.source` all return FAILURE.
 * - Ref policy: user-typed ref stored verbatim; auto-resolved
 *   stable semver formatted as `^X.Y.Z`; auto-resolved non-semver
 *   (branch HEAD) stores no ref.
 */
#[Test]
#[Covers(AddRunner::class)]
#[Covers(AddOptions::class)]
final class AddRunnerTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-add-runner-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    // ── happy paths ────────────────────────────────────────────────

    public function happyPathWritesEntryAndPrintsSummary(): void
    {
        $adapter = StubAdapter::withDefaults();
        $fetcher = $this->fetcherReturning($this->writeArchive('happy-pkg', 'acme/skills', 'skills'));
        $io = new BufferIO();

        $code = $this->runner($adapter, $fetcher)->run(
            Path::create($this->tmp),
            $io,
            new AddOptions(input: 'acme/skills', from: ProviderId::GITHUB, ref: 'v1.2.3'),
        );

        Assert::same($code, Command::SUCCESS);
        Assert::true(\str_contains($io->getOutput(), 'registered github:acme/skills @ v1.2.3'));

        $skills = $this->readSkillsJson();
        Assert::count((array) $skills['remote'], 1);
        Assert::same($skills['remote'][0]['ref'] ?? null, 'v1.2.3');
    }

    public function inferredAdapterIsUsedWhenInputIsUrlAndFromIsAbsent(): void
    {
        // The runner walks registered adapters and picks the one whose
        // defaultHost() matches the URL host. Setting the stub's host
        // to https://github.com makes it pick us up.
        $adapter = StubAdapter::withDefaults();
        $fetcher = $this->fetcherReturning($this->writeArchive('inferred', 'acme/skills', 'skills'));
        $io = new BufferIO();

        $code = $this->runner($adapter, $fetcher)->run(
            Path::create($this->tmp),
            $io,
            new AddOptions(input: 'https://github.com/acme/skills'),
        );

        Assert::same($code, Command::SUCCESS, 'stderr: ' . $io->getOutput());
    }

    // ── ref policy ─────────────────────────────────────────────────

    public function userTypedRefIsStoredVerbatim(): void
    {
        // The user's `--ref=v1.2.3` lands in skills.json unchanged —
        // no caret derivation, even though v1.2.3 is a stable semver.
        $adapter = StubAdapter::withDefaults();
        $fetcher = $this->fetcherReturning($this->writeArchive('v', 'acme/skills', 'skills'));

        $this->runner($adapter, $fetcher)->run(
            Path::create($this->tmp),
            new BufferIO(),
            new AddOptions(
                input: 'acme/skills',
                from: ProviderId::GITHUB,
                ref: 'v1.2.3',
            ),
        );

        Assert::same($this->readSkillsJson()['remote'][0]['ref'] ?? null, 'v1.2.3');
    }

    public function noRefPlusStableResolutionStoresCaret(): void
    {
        // User did NOT pass --ref; the adapter resolved to a stable
        // semver tag → the runner derives `^X.Y.Z` for storage.
        $adapter = new StubAdapter(resolveTo: new RemoteDonorRef(
            url: 'https://example.com/zip',
            ref: 'v2.5.7',
        ));
        $fetcher = $this->fetcherReturning($this->writeArchive('caret', 'acme/skills', 'skills'));

        $this->runner($adapter, $fetcher)->run(
            Path::create($this->tmp),
            new BufferIO(),
            new AddOptions(input: 'acme/skills', from: ProviderId::GITHUB),
        );

        Assert::same($this->readSkillsJson()['remote'][0]['ref'] ?? null, '^2.5.7');
    }

    public function noRefPlusBranchResolutionOmitsRef(): void
    {
        // Cascade fell through to default branch (non-semver). No
        // stable caret can be derived → store no ref at all. Sync
        // re-cascades next time.
        $adapter = new StubAdapter(resolveTo: new RemoteDonorRef(
            url: 'https://example.com/zip',
            ref: 'main',
        ));
        $fetcher = $this->fetcherReturning($this->writeArchive('branch', 'acme/skills', 'skills'));

        $this->runner($adapter, $fetcher)->run(
            Path::create($this->tmp),
            new BufferIO(),
            new AddOptions(input: 'acme/skills', from: ProviderId::GITHUB),
        );

        Assert::false(
            \array_key_exists('ref', $this->readSkillsJson()['remote'][0]),
            'no stable ref ⇒ ref field is omitted',
        );
    }

    // ── adapter selection failures ─────────────────────────────────

    public function unknownFromIdReturnsInvalid(): void
    {
        // Registry has only the github stub; --from=gitlab is rejected
        // before any I/O is attempted.
        $adapter = StubAdapter::withDefaults();
        $fetcher = new ExplodingFetcher();
        $io = new BufferIO();

        $code = $this->runner($adapter, $fetcher)->run(
            Path::create($this->tmp),
            $io,
            new AddOptions(input: 'acme/skills', from: 'gitlab'),
        );

        Assert::same($code, Command::INVALID);
        Assert::true(\str_contains($io->getOutput(), 'no remote adapter registered'));
    }

    public function shorthandWithoutFromDefaultsToGithub(): void
    {
        // GitHub is the overwhelmingly common donor source; `--from`
        // is optional for shorthand input. The runner routes through
        // the registered "github" adapter exactly as if `--from=github`
        // had been passed.
        $adapter = StubAdapter::withDefaults();
        $fetcher = $this->fetcherReturning($this->writeArchive('def', 'acme/skills', 'skills'));
        $io = new BufferIO();

        $code = $this->runner($adapter, $fetcher)->run(
            Path::create($this->tmp),
            $io,
            new AddOptions(input: 'acme/skills'),
        );

        Assert::same($code, Command::SUCCESS, 'stderr: ' . $io->getOutput());
        Assert::true(\str_contains($io->getOutput(), 'registered github:acme/skills'));
    }

    public function urlWithUnknownHostIsRejectedWithUrlSpecificMessage(): void
    {
        // The input *is* a full URL but no adapter claims its host.
        // The message must mention the URL, not say "shorthand"
        // (copilot review #8 on PR #15 caught the misleading wording).
        $adapter = StubAdapter::withDefaults();
        $fetcher = new ExplodingFetcher();
        $io = new BufferIO();

        $code = $this->runner($adapter, $fetcher)->run(
            Path::create($this->tmp),
            $io,
            new AddOptions(input: 'https://unknown.example.com/acme/skills'),
        );

        Assert::same($code, Command::INVALID);
        $out = $io->getOutput();
        Assert::true(\str_contains($out, 'could not infer adapter'));
        Assert::true(\str_contains($out, 'https://unknown.example.com/acme/skills'));
        Assert::false(\str_contains($out, 'shorthand'));
    }

    // ── per-step errors ────────────────────────────────────────────

    public function parseAddInputErrorReturnsInvalid(): void
    {
        $adapter = new StubAdapter(parseError: 'malformed input');
        $fetcher = new ExplodingFetcher();
        $io = new BufferIO();

        $code = $this->runner($adapter, $fetcher)->run(
            Path::create($this->tmp),
            $io,
            new AddOptions(input: 'acme/skills', from: ProviderId::GITHUB),
        );

        Assert::same($code, Command::INVALID);
        Assert::true(\str_contains($io->getOutput(), 'malformed input'));
    }

    public function resolveExceptionReturnsFailure(): void
    {
        $adapter = new StubAdapter(resolveError: 'no matching tag for ^99.0');
        $fetcher = new ExplodingFetcher();
        $io = new BufferIO();

        $code = $this->runner($adapter, $fetcher)->run(
            Path::create($this->tmp),
            $io,
            new AddOptions(input: 'acme/skills', from: ProviderId::GITHUB),
        );

        Assert::same($code, Command::FAILURE);
        Assert::true(\str_contains($io->getOutput(), 'no matching tag'));
    }

    public function fetchExceptionReturnsFailure(): void
    {
        $adapter = StubAdapter::withDefaults();
        $fetcher = new ThrowingFetcher('transport timeout');
        $io = new BufferIO();

        $code = $this->runner($adapter, $fetcher)->run(
            Path::create($this->tmp),
            $io,
            new AddOptions(input: 'acme/skills', from: ProviderId::GITHUB),
        );

        Assert::same($code, Command::FAILURE);
        Assert::true(\str_contains($io->getOutput(), 'transport timeout'));
    }

    // ── archive validation ─────────────────────────────────────────

    public function missingComposerJsonReturnsFailure(): void
    {
        $adapter = StubAdapter::withDefaults();
        $fetcher = $this->fetcherReturning($this->writeBareDir('no-cj'));
        $io = new BufferIO();

        $code = $this->runner($adapter, $fetcher)->run(
            Path::create($this->tmp),
            $io,
            new AddOptions(input: 'acme/skills', from: ProviderId::GITHUB),
        );

        Assert::same($code, Command::FAILURE);
        Assert::true(\str_contains($io->getOutput(), 'does not contain a composer.json'));
    }

    public function invalidComposerJsonReturnsFailure(): void
    {
        $extracted = $this->writeBareDir('bad-json');
        \file_put_contents($extracted . '/composer.json', '{not json');

        $adapter = StubAdapter::withDefaults();
        $fetcher = $this->fetcherReturning($extracted);
        $io = new BufferIO();

        $code = $this->runner($adapter, $fetcher)->run(
            Path::create($this->tmp),
            $io,
            new AddOptions(input: 'acme/skills', from: ProviderId::GITHUB),
        );

        Assert::same($code, Command::FAILURE);
        Assert::true(\str_contains($io->getOutput(), 'not valid JSON'));
    }

    public function archiveWithoutExtraSkillsSourceReturnsFailure(): void
    {
        // composer.json exists and is valid JSON, but the package
        // does not declare itself as a skills donor — refuse to
        // register it so a future sync doesn't silently no-op.
        $extracted = $this->writeBareDir('no-source');
        \file_put_contents(
            $extracted . '/composer.json',
            \json_encode(['name' => 'acme/skills'], \JSON_THROW_ON_ERROR),
        );

        $adapter = StubAdapter::withDefaults();
        $fetcher = $this->fetcherReturning($extracted);
        $io = new BufferIO();

        $code = $this->runner($adapter, $fetcher)->run(
            Path::create($this->tmp),
            $io,
            new AddOptions(input: 'acme/skills', from: ProviderId::GITHUB),
        );

        Assert::same($code, Command::FAILURE);
        Assert::true(\str_contains($io->getOutput(), 'does not declare extra.skills.source'));
    }

    // ── writer failure ─────────────────────────────────────────────

    public function writerFailureReturnsFailure(): void
    {
        // SkillsJsonWriter is final, so we can't subclass it.
        // Instead, force a real write failure: pre-create a directory
        // at skills.json's path. The atomicWrite's rename(temp, target)
        // refuses to overwrite a directory, so the writer throws and
        // the runner returns FAILURE.
        \mkdir($this->tmp . '/skills.json', 0o777, true);

        $adapter = StubAdapter::withDefaults();
        $fetcher = $this->fetcherReturning($this->writeArchive('writer-fail', 'acme/skills', 'skills'));
        $io = new BufferIO();

        $code = $this->runner($adapter, $fetcher)->run(
            Path::create($this->tmp),
            $io,
            new AddOptions(input: 'acme/skills', from: ProviderId::GITHUB),
        );

        Assert::same($code, Command::FAILURE);
        Assert::true(\str_contains($io->getOutput(), 'failed to update skills.json'));
    }

    // ── helpers ────────────────────────────────────────────────────

    private function runner(HostAdapter $adapter, RemoteFetcher $fetcher): AddRunner
    {
        return new AddRunner(
            new HostAdapterRegistry($adapter),
            $fetcher,
        );
    }

    /**
     * @return non-empty-string absolute path of the fake extracted archive
     */
    private function writeArchive(string $label, string $packageName, string $source): string
    {
        $dir = $this->writeBareDir($label);
        \file_put_contents(
            $dir . '/composer.json',
            \json_encode(
                ['name' => $packageName, 'extra' => ['skills' => ['source' => $source]]],
                \JSON_THROW_ON_ERROR,
            ),
        );
        return $dir;
    }

    /**
     * @return non-empty-string
     */
    private function writeBareDir(string $label): string
    {
        $dir = $this->tmp . '/' . $label;
        \mkdir($dir, 0o777, true);
        return $dir;
    }

    /**
     * @param non-empty-string $extractedPath
     */
    private function fetcherReturning(string $extractedPath): RemoteFetcher
    {
        return new class($extractedPath) implements RemoteFetcher {
            public function __construct(
                /** @var non-empty-string */
                private readonly string $extractedPath,
            ) {}

            #[\Override]
            public function fetch(RemoteDonorRef $ref): Path
            {
                return Path::create($this->extractedPath);
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function readSkillsJson(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode(
            (string) \file_get_contents($this->tmp . '/skills.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        return $decoded;
    }
}

/**
 * Test-double adapter that lets each test pin the parse / resolve
 * behaviour. Defaults to "accept any input, resolve to v1.0.0" so
 * happy-path tests stay short.
 *
 * @internal
 */
final class StubAdapter implements HostAdapter
{
    public function __construct(
        private readonly ?string $parseError = null,
        private readonly ?string $resolveError = null,
        private readonly ?RemoteDonorRef $resolveTo = null,
    ) {}

    public static function withDefaults(): self
    {
        return new self();
    }

    #[\Override]
    public function id(): string
    {
        return ProviderId::GITHUB;
    }

    #[\Override]
    public function defaultHost(): string
    {
        return 'https://github.com';
    }

    #[\Override]
    public function parseAddInput(
        string $input,
        ?string $hostOverride = null,
        ?string $refOverride = null,
    ): ParsedAddInput {
        if ($this->parseError !== null) {
            throw new \InvalidArgumentException($this->parseError);
        }
        // Pull `owner/repo` out of either shorthand or full URL —
        // enough to feed the runner a plausible ParsedAddInput.
        $pkg = $input;
        if (\preg_match('~/([^/]+/[^/?#]+)~', $input, $m) === 1) {
            $pkg = $m[1];
        }
        /** @var non-empty-string $pkg */
        return new ParsedAddInput(
            from: ProviderId::GITHUB,
            package: $pkg,
            url: null,
            host: $hostOverride,
            ref: $refOverride,
        );
    }

    #[\Override]
    public function resolve(RemoteEntry $entry): RemoteDonorRef
    {
        if ($this->resolveError !== null) {
            throw new RemoteResolveException($entry, $this->resolveError);
        }
        if ($this->resolveTo !== null) {
            return $this->resolveTo;
        }
        return new RemoteDonorRef(
            url: 'https://example.com/' . ($entry->package ?? 'pkg') . '.zip',
            ref: $entry->ref ?? 'v1.0.0',
        );
    }
}

/**
 * Fetcher that throws {@see RemoteFetchException} with a controllable
 * message. Used to assert the runner converts fetcher failures into
 * Command::FAILURE with the right message.
 *
 * @internal
 */
final class ThrowingFetcher implements RemoteFetcher
{
    public function __construct(
        private readonly string $reason,
    ) {}

    #[\Override]
    public function fetch(RemoteDonorRef $ref): Path
    {
        throw new RemoteFetchException($ref, $this->reason);
    }
}

/**
 * Fetcher whose `fetch()` must never be called. Used in tests that
 * verify the runner short-circuits before reaching the fetcher.
 *
 * @internal
 */
final class ExplodingFetcher implements RemoteFetcher
{
    #[\Override]
    public function fetch(RemoteDonorRef $ref): Path
    {
        throw new \LogicException('fetcher should not have been called');
    }
}
