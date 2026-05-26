<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Console;

use LLM\Skills\Console\AddCliDefinition;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

/**
 * Verifies that {@see AddCliDefinition} produces the right
 * {@see \LLM\Skills\Config\AddOptions} for every CLI shape
 * `skills:add` accepts.
 *
 * The pattern is the same one used for the sync CLI: configure a real
 * Symfony Command, feed it an {@see ArrayInput}, then run
 * `buildOptions()` against the bound input. No subprocess, no IO.
 */
#[Test]
#[Covers(AddCliDefinition::class)]
final class AddCliDefinitionTest
{
    public function defaultsCarryThroughWhenOnlyInputIsGiven(): void
    {
        $opts = $this->build(['input' => 'acme/skills']);

        Assert::same($opts->input, 'acme/skills');
        Assert::same($opts->from, null);
        Assert::same($opts->host, null);
        Assert::same($opts->ref, null);
        Assert::true($opts->sync, 'sync defaults to true so add behaves like composer require');
    }

    public function fromHostRefArePropagated(): void
    {
        $opts = $this->build([
            'input' => 'team/skills',
            '--from' => 'github',
            '--host' => 'https://github.corp.example.com',
            '--ref' => '^1.2.0',
        ]);

        Assert::same($opts->from, 'github');
        Assert::same($opts->host, 'https://github.corp.example.com');
        Assert::same($opts->ref, '^1.2.0');
    }

    public function noSyncFlagFlipsSyncToFalse(): void
    {
        // `--no-sync` is the only way to suppress the follow-up
        // sync; the auto-sync is the default behaviour.
        $opts = $this->build(['input' => 'acme/skills', '--no-sync' => true]);

        Assert::false($opts->sync);
    }

    public function emptyInputThrows(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('input must be a non-empty string');

        $this->build(['input' => '']);
    }

    public function emptyFromOptionThrows(): void
    {
        // `--from=""` is malformed input — we surface it loudly
        // instead of silently treating it as "infer from input".
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('--from must be a non-empty string');

        $this->build(['input' => 'acme/skills', '--from' => '']);
    }

    public function emptyHostOptionThrows(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('--host must be a non-empty string');

        $this->build(['input' => 'acme/skills', '--host' => '']);
    }

    public function emptyRefOptionThrows(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('--ref must be a non-empty string');

        $this->build(['input' => 'acme/skills', '--ref' => '']);
    }

    public function commandMetadataIsApplied(): void
    {
        // Pin the name + alias plumbing so a future refactor of
        // `apply()` cannot silently drop the shorthand.
        $cmd = new Command();
        AddCliDefinition::apply($cmd, 'skills:add', ['skills:a']);

        Assert::same($cmd->getName(), 'skills:add');
        Assert::same($cmd->getAliases(), ['skills:a']);
        Assert::true(\str_contains($cmd->getDescription(), 'Register a remote donor'));
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private function build(array $inputs): \LLM\Skills\Config\AddOptions
    {
        $cmd = new Command();
        AddCliDefinition::apply($cmd, 'skills:add');

        $input = new ArrayInput($inputs, $cmd->getDefinition());
        return AddCliDefinition::buildOptions($input);
    }
}
