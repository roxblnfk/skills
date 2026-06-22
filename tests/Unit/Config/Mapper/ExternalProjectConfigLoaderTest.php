<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config\Mapper;

use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\Mapper\ExternalProjectConfigLoader;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
final class ExternalProjectConfigLoaderTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-ext-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function returnsNullWhenFileIsAbsent(): void
    {
        $result = (new ExternalProjectConfigLoader())->load(Path::create($this->tmp));

        Assert::null($result);
    }

    public function decodesValidObject(): void
    {
        $this->writeFile([
            'target' => '.agents/skills',
            'aliases' => ['.claude/skills'],
        ]);

        $result = (new ExternalProjectConfigLoader())->load(Path::create($this->tmp));

        Assert::same($result, [
            'target' => '.agents/skills',
            'aliases' => ['.claude/skills'],
        ]);
    }

    public function stripsSchemaKeyFromResult(): void
    {
        // `$schema` is editor metadata; the loader strips it so downstream
        // code never sees it. The other keys must come through unchanged.
        $this->writeFile([
            '$schema' => 'https://example.com/skills.schema.json',
            'target' => '.agents/skills',
        ]);

        $result = (new ExternalProjectConfigLoader())->load(Path::create($this->tmp));

        Assert::same($result, ['target' => '.agents/skills']);
    }

    public function allowsPathFromRootKey(): void
    {
        $this->writeFile([
            'target' => '.agents/skills',
            'path-from-root' => 'packages/api',
        ]);

        $result = (new ExternalProjectConfigLoader())->load(Path::create($this->tmp));

        Assert::same($result, [
            'target' => '.agents/skills',
            'path-from-root' => 'packages/api',
        ]);
    }

    public function emptyObjectDecodesAsEmptyArray(): void
    {
        // `{}` is a valid (if uninteresting) skills.json — it means "no
        // project-level overrides, use defaults across the board". Written
        // as the literal JSON object form because `json_encode([])`
        // emits the array form `[]`, which the loader correctly rejects.
        \file_put_contents($this->tmp . '/skills.json', '{}');

        $result = (new ExternalProjectConfigLoader())->load(Path::create($this->tmp));

        Assert::same($result, []);
    }

    public function invalidJsonThrows(): void
    {
        \file_put_contents($this->tmp . '/skills.json', '{not valid json');

        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('skills.json: invalid JSON');

        (new ExternalProjectConfigLoader())->load(Path::create($this->tmp));
    }

    public function nonObjectRootThrows(): void
    {
        // A scalar at the root is rejected — the loader expects an object.
        \file_put_contents($this->tmp . '/skills.json', '"hello"');

        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('skills.json: root must be a JSON object');

        (new ExternalProjectConfigLoader())->load(Path::create($this->tmp));
    }

    public function listRootThrows(): void
    {
        // A non-empty JSON array must be rejected — the contract is
        // "root is a JSON object".
        \file_put_contents($this->tmp . '/skills.json', '[1, 2, 3]');

        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('root must be a JSON object');

        (new ExternalProjectConfigLoader())->load(Path::create($this->tmp));
    }

    public function emptyListRootThrows(): void
    {
        // Edge case: `[]` and `{}` both decode to PHP `[]` under
        // `json_decode(..., true)`. The loader must still tell them
        // apart and reject the array form — that's what the
        // `assoc=false` first-pass type check is for.
        \file_put_contents($this->tmp . '/skills.json', '[]');

        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('root must be a JSON object');

        (new ExternalProjectConfigLoader())->load(Path::create($this->tmp));
    }

    public function unknownTopLevelKeyThrows(): void
    {
        // Strictness contract: only the documented keys are accepted.
        $this->writeFile([
            'target' => '.agents/skills',
            'unexpected-key' => 'oops',
        ]);

        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('unexpected-key');

        (new ExternalProjectConfigLoader())->load(Path::create($this->tmp));
    }

    public function configFileKeyInsideExternalFileIsRejected(): void
    {
        // The previously-considered config-file pointer is project-only;
        // including it inside skills.json itself is nonsensical and the
        // strict-keys check refuses it.
        $this->writeFile([
            'config-file' => 'other.json',
            'target' => '.agents/skills',
        ]);

        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('config-file');

        (new ExternalProjectConfigLoader())->load(Path::create($this->tmp));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeFile(array $data): void
    {
        \file_put_contents(
            $this->tmp . '/skills.json',
            \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
        );
    }
}
