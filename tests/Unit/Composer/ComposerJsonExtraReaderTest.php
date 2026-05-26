<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Composer;

use Composer\IO\BufferIO;
use Internal\Path;
use LLM\Skills\Composer\ComposerJsonExtraReader;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Unit coverage for the `composer.json` `extra` fallback reader.
 *
 * Used by `bin/skills` entrypoints when `Composer\Factory::create()`
 * throws but `composer.json` is still readable: the user's inline
 * `extra.skills` block must keep flowing into the runner so the
 * legacy fallback contract holds. Each non-trivial failure path
 * here returns `null` rather than throwing — the caller has no
 * recovery available beyond "no extras".
 */
#[Test]
final class ComposerJsonExtraReaderTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-extra-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function returnsNullWhenComposerJsonIsAbsent(): void
    {
        $result = (new ComposerJsonExtraReader())->read(
            Path::create($this->tmp),
            new BufferIO(),
        );

        Assert::null($result);
    }

    public function returnsExtraValueWhenPresent(): void
    {
        // The reader returns the raw `extra` field as-is, including
        // any nested `skills` block — the mapper downstream handles
        // shape validation.
        \file_put_contents(
            $this->tmp . '/composer.json',
            \json_encode([
                'name' => 'demo/consumer',
                'extra' => [
                    'skills' => ['target' => 'custom/skills'],
                ],
            ], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        $result = (new ComposerJsonExtraReader())->read(
            Path::create($this->tmp),
            new BufferIO(),
        );

        Assert::same($result, ['skills' => ['target' => 'custom/skills']]);
    }

    public function returnsNullWhenExtraKeyIsAbsent(): void
    {
        // composer.json may legitimately have no `extra` at all —
        // that's a project with no inline customisation, not an error.
        \file_put_contents(
            $this->tmp . '/composer.json',
            \json_encode(['name' => 'demo/no-extra'], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );

        $result = (new ComposerJsonExtraReader())->read(
            Path::create($this->tmp),
            new BufferIO(),
        );

        Assert::null($result);
    }

    public function returnsNullAndWarnsOnMalformedJson(): void
    {
        // A broken composer.json is what Factory::create() may have
        // tripped over in the first place. We do not re-fail the run
        // (the caller already accepted that no Composer is available);
        // we just disable the inline fallback and tell the user under -v.
        \file_put_contents($this->tmp . '/composer.json', '{ definitely not valid json');

        $io = new BufferIO(verbosity: \Symfony\Component\Console\Output\StreamOutput::VERBOSITY_VERBOSE);
        $result = (new ComposerJsonExtraReader())->read(Path::create($this->tmp), $io);

        Assert::null($result);
        Assert::true(
            \str_contains($io->getOutput(), 'inline extra.skills fallback disabled'),
            '-v output must explain why the fallback is empty. Got: ' . $io->getOutput(),
        );
    }

    public function returnsNullWhenRootIsNotAnObject(): void
    {
        // A JSON array at the root is technically valid JSON but not a
        // Composer manifest. The reader simply yields null — same
        // user-visible outcome as "no extras".
        \file_put_contents($this->tmp . '/composer.json', '[1, 2, 3]');

        $result = (new ComposerJsonExtraReader())->read(
            Path::create($this->tmp),
            new BufferIO(),
        );

        Assert::null($result);
    }

    public function returnsExtraWhenItIsAnEmptyObject(): void
    {
        // An empty `extra` decodes to an empty array — distinguishable
        // from "no extra key" only by the caller. We propagate it
        // verbatim; the project-config mapper treats `[]` as "use
        // defaults" anyway.
        \file_put_contents(
            $this->tmp . '/composer.json',
            '{"name": "demo/empty-extra", "extra": {}}',
        );

        $result = (new ComposerJsonExtraReader())->read(
            Path::create($this->tmp),
            new BufferIO(),
        );

        Assert::same($result, []);
    }
}
