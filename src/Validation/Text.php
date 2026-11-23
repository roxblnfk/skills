<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

/**
 * UTF-8 helpers that degrade to byte semantics when `mbstring` or `intl` is missing.
 *
 * @internal
 *
 * @psalm-suppress ImpureFunctionCall extension availability does not change within a run
 * @psalm-suppress ImpureMethodCall Normalizer::normalize() depends only on its arguments
 */
final class Text
{
    /**
     * @return int<0, max> length in code points
     *
     * @psalm-pure
     */
    public static function length(string $s): int
    {
        if (\function_exists('mb_strlen')) {
            return \mb_strlen($s, 'UTF-8');
        }

        return \max(0, (int) \preg_match_all('/./su', $s));
    }

    /**
     * UTF-8 encoding of a code point.
     *
     * @psalm-pure
     */
    public static function chr(int $codePoint): string
    {
        return match (true) {
            $codePoint < 0x80 => \chr($codePoint),
            $codePoint < 0x800 => \chr(0xC0 | $codePoint >> 6) . \chr(0x80 | $codePoint & 0x3F),
            $codePoint < 0x10000 => \chr(0xE0 | $codePoint >> 12) . \chr(0x80 | $codePoint >> 6 & 0x3F)
                . \chr(0x80 | $codePoint & 0x3F),
            default => \chr(0xF0 | $codePoint >> 18) . \chr(0x80 | $codePoint >> 12 & 0x3F)
                . \chr(0x80 | $codePoint >> 6 & 0x3F) . \chr(0x80 | $codePoint & 0x3F),
        };
    }

    /**
     * @psalm-pure
     */
    public static function nfkc(string $s): string
    {
        if (\class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_KC);

            return \is_string($n) ? $n : $s;
        }

        return $s;
    }

    /**
     * @psalm-pure
     */
    public static function lower(string $s): string
    {
        return \function_exists('mb_strtolower') ? \mb_strtolower($s, 'UTF-8') : \strtolower($s);
    }

    /**
     * The candidate within a small edit distance of `$word`, for "did you mean" hints;
     * `null` when none is close enough or `$word` is itself a candidate.
     *
     * @param list<string> $candidates
     *
     * @psalm-pure
     */
    public static function nearest(string $word, array $candidates): ?string
    {
        $best = null;
        $bestDistance = \PHP_INT_MAX;
        $w = \strtolower(\str_replace('_', '-', $word));
        foreach ($candidates as $candidate) {
            $d = \levenshtein($w, \str_replace('_', '-', $candidate));
            if ($d < $bestDistance) {
                $best = $candidate;
                $bestDistance = $d;
            }
        }
        $limit = \max(1, \min(3, \intdiv(\strlen($word), 3)));

        return $best !== null && $bestDistance <= $limit && $best !== $word ? $best : null;
    }
}
