<?php

declare(strict_types=1);

namespace LLM\Skills\Discovery\Provider\Source\Adapter;

use LLM\Skills\Config\SourceEntry;

/**
 * Raised when a {@see HostAdapter} cannot resolve a stored
 * `sources[]` entry into a fetchable {@see \LLM\Skills\Discovery\Provider\Source\RemoteDonorRef}.
 *
 * Examples:
 *
 * - Caret / tilde constraint with no matching tag in the repository.
 * - Repo has no tags AND no default branch (extremely rare; usually
 *   a misconfiguration).
 * - API returned a non-success status the adapter cannot recover
 *   from (404 on `/repos/...`, 401 with no auth).
 *
 * Transport-level errors (DNS, timeout) come through as
 * {@see \LLM\Skills\Discovery\Provider\Source\Http\HttpException},
 * which the adapter wraps in `RemoteResolveException` with the
 * surrounding context so the runner can show a single user-facing
 * message that names both the entry and the underlying cause.
 */
final class RemoteResolveException extends \RuntimeException
{
    /**
     * @param non-empty-string $reason cause on its own, with no entry context. Kept as a
     *        property because {@see self::getMessage()} prefixes it with `remote <from>:<id> — `
     *        for the log stream, while the summary listing already prints the entry as a
     *        row header and needs the bare clause.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly SourceEntry $entry,
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf(
                'remote %s:%s — %s',
                $entry->from,
                $entry->identifier(),
                $reason,
            ),
            previous: $previous,
        );
    }
}
