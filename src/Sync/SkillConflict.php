<?php

declare(strict_types=1);

namespace LLM\Skills\Sync;

/**
 * Two or more donor packages declared a skill with the same directory name.
 *
 * When the engine detects any conflict, sync aborts before touching the
 * filesystem: we refuse to silently pick a winner.
 *
 * @psalm-immutable
 */
final readonly class SkillConflict
{
    /**
     * @param non-empty-string $name conflicting skill directory name
     * @param list<non-empty-string> $packages Composer names of the conflicting donors, in the order they
     *        were enumerated
     * @param list<non-empty-string> $names the spelling each donor in `$packages` declared, by position;
     *        they differ from `$name` when the names collide only under {@see self::collisionKey()}.
     *        Empty means every donor spelled it `$name`.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        public string $name,
        public array $packages,
        public array $names = [],
    ) {}

    /**
     * The key two skill names collide under: Windows and macOS file systems
     * fold letter case, and macOS also treats composed and decomposed Unicode
     * as one name. Folding is one character to one, as file systems do: `ß`
     * stays apart from `ss`. It needs the `intl` and `mbstring` extensions;
     * without them only ASCII case is folded, which is why discovery refuses
     * non-ASCII names unless {@see self::foldsUnicode()} holds.
     *
     * @psalm-pure
     *
     * @psalm-suppress ImpureFunctionCall extension availability does not change within a run
     */
    public static function collisionKey(string $name): string
    {
        // Folding rewrites invalid UTF-8 to `?`, which would make distinct
        // byte names collide; such names fold by ASCII case only.
        if (\preg_match('//u', $name) !== 1) {
            return \strtolower($name);
        }

        if (\class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
            if (\is_string($normalized)) {
                $name = $normalized;
            }
        }

        return \function_exists('mb_convert_case')
            ? \mb_convert_case($name, \MB_CASE_FOLD_SIMPLE, 'UTF-8')
            : \strtolower($name);
    }

    /**
     * Whether {@see self::collisionKey()} folds every name the way the file
     * systems do. Without `mbstring` `Über` and `über` get distinct keys, and
     * without `intl` so do the composed and decomposed spellings of `ü`.
     *
     * @psalm-pure
     *
     * @psalm-suppress ImpureFunctionCall extension availability does not change within a run
     */
    public static function foldsUnicode(): bool
    {
        return \class_exists(\Normalizer::class) && \function_exists('mb_convert_case');
    }

    /**
     * Each donor, followed by the spelling it declared when that differs from `$name`.
     *
     * @return list<non-empty-string>
     *
     * @psalm-mutation-free
     */
    public function declarations(): array
    {
        $out = [];
        foreach ($this->packages as $i => $package) {
            $spelling = $this->names[$i] ?? $this->name;
            $out[] = $spelling === $this->name ? $package : \sprintf('%s (as "%s")', $package, $spelling);
        }

        return $out;
    }
}
