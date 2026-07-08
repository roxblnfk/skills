<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider\Source\Http;

use Composer\Config;
use Composer\IO\NullIO;
use LLM\Skills\Discovery\Provider\Source\Http\ComposerHttpClient;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Unit coverage for {@see ComposerHttpClient::fromConfig()}.
 *
 * The thing worth pinning is the auth wiring: Composer keeps tokens on
 * the IO (not the {@see Config}), so the factory must call
 * {@see \Composer\IO\IOInterface::loadConfiguration()} on the IO that
 * backs the downloader — otherwise every request goes out anonymous and
 * private GitLab projects 404. We assert the token lands in the IO's
 * authentication store via the injectable `$io` seam.
 */
#[Test]
#[Covers(ComposerHttpClient::class)]
final class ComposerHttpClientTest
{
    public function fromConfigLoadsGitlabTokenIntoTheIo(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['gitlab-token' => ['gitlab.example.com' => 'sekret-token']]]);

        $io = new NullIO();
        ComposerHttpClient::fromConfig($config, $io);

        // A personal access token is stored as username + the literal
        // 'private-token' password, which AuthHelper turns into a
        // `PRIVATE-TOKEN: sekret-token` header for that origin.
        Assert::true($io->hasAuthentication('gitlab.example.com'));
        $auth = $io->getAuthentication('gitlab.example.com');
        Assert::same($auth['username'], 'sekret-token');
        Assert::same($auth['password'], 'private-token');
    }

    public function fromConfigImplicitlyRegistersTheTokenHostAsAGitlabDomain(): void
    {
        // loadConfiguration auto-adds any host that has a gitlab-token to
        // gitlab-domains — so a self-hosted host needs only the token,
        // not a separate `composer config gitlab-domains` step.
        $config = new Config(false);
        $config->merge(['config' => ['gitlab-token' => ['gitlab.example.com' => 'sekret-token']]]);

        ComposerHttpClient::fromConfig($config, new NullIO());

        Assert::true(
            \in_array('gitlab.example.com', $config->get('gitlab-domains'), true),
            'token host must be registered as a gitlab domain so the token is attached',
        );
    }

    public function fromConfigUsesAGithubTokenTheSameWay(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['github-oauth' => ['github.com' => 'gho_exampletoken']]]);

        $io = new NullIO();
        ComposerHttpClient::fromConfig($config, $io);

        Assert::true($io->hasAuthentication('github.com'));
    }

    public function fromConfigSwallowsAMalformedTokenInsteadOfThrowing(): void
    {
        // A bad token in auth.json must not abort discovery/sync — the
        // factory degrades to anonymous and the fetch later surfaces the
        // 401/404 with the adapter's auth hint. A github token with
        // illegal characters is the case loadConfiguration rejects.
        $config = new Config(false);
        $config->merge(['config' => ['github-oauth' => ['github.com' => 'has spaces and *bad* chars']]]);

        // Must not throw.
        $client = ComposerHttpClient::fromConfig($config, new NullIO());

        Assert::true($client instanceof ComposerHttpClient);
    }
}
