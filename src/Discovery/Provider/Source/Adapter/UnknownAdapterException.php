<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Source\Adapter;

/**
 * Raised by {@see HostAdapterRegistry} when it is asked for an
 * adapter id no implementation has registered for.
 *
 * Two callers hit this:
 *
 * - The remote source ({@see \LLM\Skills\Discovery\Provider\Source\SkillsJsonDonorRefSource})
 *   when an entry's `from` value passed schema validation
 *   but no adapter has been bound for it yet — v1 will only register
 *   `github`, so `gitlab` / `npm` / etc. entries will reach this path.
 * - The `skills:add` CLI when `--from` names an unimplemented adapter.
 *
 * The message names the offending id and the registered adapters so
 * the user can either fix the typo or wait for the missing
 * implementation.
 */
final class UnknownAdapterException extends \RuntimeException
{
    /**
     * @param non-empty-string $id the requested id
     * @param list<non-empty-string> $known ids currently registered
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly string $id,
        public readonly array $known,
    ) {
        parent::__construct(\sprintf(
            'no remote adapter registered for "%s" (registered: %s)',
            $id,
            $known === [] ? '<none>' : \implode(', ', $known),
        ));
    }
}
