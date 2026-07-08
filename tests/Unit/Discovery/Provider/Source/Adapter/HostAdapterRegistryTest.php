<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Discovery\Provider\Source\Adapter;

use LLM\Skills\Config\SourceEntry;
use LLM\Skills\Discovery\Provider\Source\Adapter\HostAdapter;
use LLM\Skills\Discovery\Provider\Source\Adapter\HostAdapterRegistry;
use LLM\Skills\Discovery\Provider\Source\Adapter\ParsedAddInput;
use LLM\Skills\Discovery\Provider\Source\Adapter\UnknownAdapterException;
use LLM\Skills\Discovery\Provider\Source\RemoteDonorRef;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(HostAdapterRegistry::class)]
#[Covers(UnknownAdapterException::class)]
final class HostAdapterRegistryTest
{
    public function getReturnsAdapterById(): void
    {
        $a = self::adapter('alpha');
        $b = self::adapter('beta');
        $registry = new HostAdapterRegistry($a, $b);

        Assert::same($registry->get('alpha'), $a);
        Assert::same($registry->get('beta'), $b);
    }

    public function hasReportsRegisteredIds(): void
    {
        $registry = new HostAdapterRegistry(self::adapter('alpha'));

        Assert::true($registry->has('alpha'));
        Assert::false($registry->has('gamma'));
    }

    public function idsAreReturnedInInsertionOrder(): void
    {
        $registry = new HostAdapterRegistry(
            self::adapter('alpha'),
            self::adapter('beta'),
            self::adapter('gamma'),
        );

        Assert::same($registry->ids(), ['alpha', 'beta', 'gamma']);
    }

    public function unknownIdThrowsWithRegisteredListInMessage(): void
    {
        // The exception names registered ids so the user can react to
        // a typo without going to docs.
        Expect::exception(UnknownAdapterException::class)
            ->withMessageContaining('no remote adapter registered for "gitlab"')
            ->withMessageContaining('registered: github');

        (new HostAdapterRegistry(self::adapter('github')))->get('gitlab');
    }

    public function emptyRegistryReportsNoRegistered(): void
    {
        Expect::exception(UnknownAdapterException::class)
            ->withMessageContaining('<none>');

        (new HostAdapterRegistry())->get('github');
    }

    public function laterAdapterWithSameIdOverridesEarlier(): void
    {
        // Last-write-wins semantic. Intentionally permitted so a test
        // can override a real adapter with a stub by appending it to
        // the registry constructor call.
        $a = self::adapter('alpha', tag: 'first');
        $b = self::adapter('alpha', tag: 'second');

        $registry = new HostAdapterRegistry($a, $b);

        Assert::same($registry->get('alpha'), $b);
    }

    /**
     * @param non-empty-string $id
     */
    private static function adapter(string $id, string $tag = ''): HostAdapter
    {
        return new class($id, $tag) implements HostAdapter {
            public function __construct(
                /** @var non-empty-string */
                private readonly string $idValue,
                public readonly string $tag,
            ) {}

            #[\Override]
            public function id(): string
            {
                return $this->idValue;
            }

            #[\Override]
            public function defaultHost(): string
            {
                return 'https://example.com';
            }

            #[\Override]
            public function parseAddInput(
                string $input,
                ?string $hostOverride = null,
                ?string $refOverride = null,
            ): ParsedAddInput {
                throw new \LogicException('not used in registry tests');
            }

            #[\Override]
            public function resolve(SourceEntry $entry): RemoteDonorRef
            {
                throw new \LogicException('not used in registry tests');
            }
        };
    }
}
