<?php

declare(strict_types=1);

namespace LLM\Skills\Console;

use Symfony\Component\Console\Input\InputInterface;

/**
 * A boolean option and its `--no-` negation read as a tri-state: `true` or
 * `false` when one of them was passed, `null` when neither was, so the value
 * can defer to the project config.
 *
 * @internal
 */
final class FlagPair
{
    /**
     * Passing both makes the negative win. Suits run-time overrides, where
     * the negative is the one that does less.
     *
     * @param non-empty-string $positive
     * @param non-empty-string $negative
     */
    public static function negativeWins(InputInterface $input, string $positive, string $negative): ?bool
    {
        if ($input->getOption($negative) === true) {
            return false;
        }

        return $input->getOption($positive) === true ? true : null;
    }

    /**
     * Passing both is a contradiction the user should hear about rather than
     * have silently resolved. Suits values written to a config file.
     *
     * @param non-empty-string $positive
     * @param non-empty-string $negative
     *
     * @throws \InvalidArgumentException when both flags are passed
     */
    public static function exclusive(InputInterface $input, string $positive, string $negative): ?bool
    {
        $on = $input->getOption($positive) === true;
        $off = $input->getOption($negative) === true;

        if ($on && $off) {
            throw new \InvalidArgumentException(\sprintf('--%s and --%s contradict each other', $positive, $negative));
        }

        return $on || $off ? $on : null;
    }
}
