<?php

declare(strict_types=1);

namespace LLM\Skills\Composer;

/**
 * Whether Composer runs in a CI job, read from the `CI` environment variable that GitHub
 * Actions, GitLab CI, Travis, CircleCI, Bitbucket Pipelines, Buildkite and others set. It is
 * the same signal Composer itself uses.
 *
 * @internal
 *
 * @psalm-pure
 */
final class CiEnvironment
{
    /**
     * `true` when `CI` is set to anything but an empty string, `0` or `false`.
     *
     * @psalm-suppress ImpureFunctionCall the environment does not change within a run
     *
     * @psalm-pure
     */
    public static function detected(): bool
    {
        $value = \getenv('CI');
        if ($value === false) {
            return false;
        }

        return !\in_array(\strtolower(\trim($value)), ['', '0', 'false'], true);
    }
}
