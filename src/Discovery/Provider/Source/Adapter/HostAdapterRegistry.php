<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Source\Adapter;

/**
 * `from`-id → {@see HostAdapter} lookup.
 *
 * Two consumers go through this:
 *
 * - `SkillsJsonDonorRefSource` — given a `sources[]`
 *   entry, asks the registry for the adapter that knows how to
 *   `resolve()` it.
 * - `skills:add` CLI — given `--from=<id>` (explicit) or
 *   an inferred-from-URL id (implicit), asks the registry for the
 *   adapter that knows how to `parseAddInput()` the user's argument.
 *
 * The registry is constructed at the entrypoint with the list of
 * adapters compiled into the binary; v1 ships only
 * {@see GithubAdapter}. Future adapters drop in by adding another
 * constructor argument here — no other code needs to change.
 *
 * The class is `final readonly` and the constructor is mutation-free;
 * the only "impure" bit is each adapter's own `resolve()` body, which
 * is queried lazily.
 *
 * @psalm-suppress MissingImmutableAnnotation
 *         the registry holds {@see HostAdapter} instances which are intentionally not pure
 */
final readonly class HostAdapterRegistry
{
    /** @var array<non-empty-string, HostAdapter> */
    private array $adapters;

    /**
     * @psalm-mutation-free
     */
    public function __construct(HostAdapter ...$adapters)
    {
        $byId = [];
        foreach ($adapters as $adapter) {
            $byId[$adapter->id()] = $adapter;
        }
        $this->adapters = $byId;
    }

    /**
     * @param non-empty-string $id
     *
     * @throws UnknownAdapterException when no adapter is registered for `$id`
     *
     * @psalm-mutation-free
     */
    public function get(string $id): HostAdapter
    {
        if (!isset($this->adapters[$id])) {
            throw new UnknownAdapterException($id, $this->ids());
        }
        return $this->adapters[$id];
    }

    /**
     * @param non-empty-string $id
     *
     * @psalm-mutation-free
     */
    public function has(string $id): bool
    {
        return isset($this->adapters[$id]);
    }

    /**
     * Registered adapter ids in insertion order. Used by error
     * messages to suggest valid alternatives.
     *
     * @return list<non-empty-string>
     *
     * @psalm-mutation-free
     */
    public function ids(): array
    {
        return \array_keys($this->adapters);
    }
}
