<?php

declare(strict_types=1);

namespace LLM\Skills\Init;

use Composer\IO\IOInterface;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Config\ProjectConfig;

/**
 * Interactive setup helper for `skills:init`.
 *
 * `skills:init` is the command the user runs deliberately — it
 * exists exactly because they want to think about their configuration.
 * Walking them through the available knobs one at a time, with
 * descriptions and sensible defaults, is the natural shape for the
 * command. Non-interactive callers (CI, `--no-interaction`) bypass
 * this class and use the silent migrate/stub flow in
 * {@see InitRunner}.
 *
 * Defaults come from one of three sources, in order:
 *
 * 1. Existing `skills.json` (`--force` re-runs surface current
 *    values).
 * 2. Inline `extra.skills` in `composer.json`, when the user
 *    confirms "import current settings" at the first prompt.
 * 3. Built-in defaults from {@see ProjectConfig::default()}.
 *
 * The wizard returns the resolved set of project keys ready to be
 * written as `skills.json`; the caller (InitRunner) handles the
 * actual file writes and the composer.json strip.
 */
final readonly class InteractiveInitWizard
{
    /**
     * Well-known alias paths offered as numbered options. Keeps the
     * user from typing the same boring strings every time. Custom
     * paths are still allowed via the follow-up free-form prompt.
     *
     * @var list<non-empty-string>
     */
    public const COMMON_ALIASES = [
        '.claude/skills',
        '.cursor/skills',
        '.agents/skills',
    ];

    /**
     * Walk the user through the wizard. Returns the resolved
     * project-keys array (suitable for `json_encode` into
     * `skills.json`) or `null` if the user aborted at the final
     * confirmation.
     *
     * @param array<string, mixed> $defaults pre-resolved defaults per
     *        {@see ProjectConfigMapper::PROJECT_KEYS}. Keys not in
     *        the array default to {@see ProjectConfig::default()}.
     *
     * @return array<string, mixed>|null
     */
    public function run(IOInterface $io, array $defaults): ?array
    {
        $io->write('');
        $io->write('<info>=====================================</info>');
        $io->write('<info>  skills.json — interactive setup    </info>');
        $io->write('<info>=====================================</info>');
        $io->write('');
        $io->write(
            'Press <comment>Enter</comment> to accept the default in [brackets]. '
            . 'Ctrl+C aborts.',
        );
        $io->write('');

        $target = $this->askTarget($io, $defaults);
        $aliases = $this->askAliases($io, $defaults, $target);
        $trusted = $this->askTrusted($io, $defaults);
        $trustedReplace = $this->askTrustedReplace($io, $defaults);
        $discovery = $this->askDiscovery($io, $defaults);
        $autoSync = $this->askAutoSync($io, $defaults);

        $result = [];
        // Order matches ProjectConfigMapper::PROJECT_KEYS so generated
        // skills.json files are diff-stable. We compare against the
        // canonical default rather than the literal string so this
        // wizard tracks any future change to DEFAULT_TARGET in one place.
        if ($target !== ProjectConfig::DEFAULT_TARGET) {
            $result['target'] = $target;
        }
        if ($aliases !== []) {
            $result['aliases'] = $aliases;
        }
        if ($trusted !== []) {
            $result['trusted'] = $trusted;
        }
        if ($trustedReplace) {
            $result['trusted-replace'] = true;
        }
        if ($discovery) {
            $result['discovery'] = true;
        }
        // auto-sync's default is `true`; only emit the key when the
        // user opted out, otherwise let the default carry it.
        if (!$autoSync) {
            $result['auto-sync'] = false;
        }

        $this->renderSummary($io, $result);

        if (!$io->askConfirmation('<info>Write skills.json? [Y/n]:</info> ', true)) {
            $io->write('<comment>[init] aborted by user.</comment>');
            return null;
        }

        return $result;
    }

    /**
     * @param list<non-empty-string> $current
     *
     * @psalm-pure
     */
    private static function encodeAliasDefault(array $current): string
    {
        $tokens = [];
        foreach ($current as $alias) {
            $idx = \array_search($alias, self::COMMON_ALIASES, true);
            $tokens[] = $idx === false ? $alias : (string) ($idx + 1);
        }

        return \implode(',', $tokens);
    }

    /**
     * Parse the freeform alias input. Empty / "none" yields `[]`.
     * Numbers map to {@see COMMON_ALIASES}; ranges (`1-3`) expand;
     * anything that isn't a number is taken as a literal path.
     *
     * @param list<non-empty-string> $defaultsForLiteral when the user accepted
     *        the prompt verbatim, keep the original list of paths verbatim
     *        rather than round-tripping through the number encoding
     *
     * @return list<non-empty-string>
     *
     * @psalm-pure
     */
    private static function parseAliasInput(string $raw, array $defaultsForLiteral): array
    {
        $raw = \trim($raw);
        if ($raw === '' || \strtolower($raw) === 'none') {
            return [];
        }

        // If the input is byte-identical to the encoded default, keep
        // the original list rather than re-decoding (preserves any
        // custom literal paths that lived alongside numbered defaults).
        if ($defaultsForLiteral !== [] && $raw === self::encodeAliasDefault($defaultsForLiteral)) {
            return $defaultsForLiteral;
        }

        $out = [];
        foreach (\explode(',', $raw) as $token) {
            $token = \trim($token);
            if ($token === '') {
                continue;
            }

            // Range: "1-3" → 1, 2, 3
            if (\preg_match('/^(\d+)-(\d+)$/', $token, $m) === 1) {
                $start = (int) $m[1];
                $end = (int) $m[2];
                if ($start > $end) {
                    [$start, $end] = [$end, $start];
                }
                for ($i = $start; $i <= $end; $i++) {
                    $path = self::COMMON_ALIASES[$i - 1] ?? null;
                    if ($path !== null) {
                        $out[] = $path;
                    }
                }
                continue;
            }

            // Single number
            if (\ctype_digit($token)) {
                $path = self::COMMON_ALIASES[((int) $token) - 1] ?? null;
                if ($path !== null) {
                    $out[] = $path;
                }
                continue;
            }

            // Anything else: literal path
            /** @var non-empty-string $token */
            $out[] = $token;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return non-empty-string
     */
    private function askTarget(IOInterface $io, array $defaults): string
    {
        /** @var mixed $rawDefault */
        $rawDefault = $defaults['target'] ?? null;
        $default = \is_string($rawDefault) && $rawDefault !== ''
            ? $rawDefault
            : ProjectConfig::DEFAULT_TARGET;

        $io->write('<info>1/6  target</info> — destination directory for synced skills,');
        $io->write('     relative to the project root. Tool-agnostic by default so');
        $io->write('     multiple agents can share it; redirect to .claude/skills,');
        $io->write('     .cursor/skills, etc. for single-agent projects.');

        /** @var mixed $answer */
        $answer = $io->ask(\sprintf('  <info>target</info> [<comment>%s</comment>]: ', $default), $default);
        $value = \is_string($answer) && $answer !== '' ? $answer : $default;
        $io->write('');

        return $value;
    }

    /**
     * Numbered selection plus free-form CSV. Accepts mixed input like
     * `1,3` or `1-3` or `2,custom/path`.
     *
     * @param array<string, mixed> $defaults
     * @param non-empty-string $target used to reject alias == target up front
     *
     * @return list<non-empty-string>
     */
    private function askAliases(IOInterface $io, array $defaults, string $target): array
    {
        $currentAliases = [];
        /** @var mixed $rawAliases */
        $rawAliases = $defaults['aliases'] ?? null;
        if (\is_array($rawAliases)) {
            /** @var mixed $value */
            foreach ($rawAliases as $value) {
                if (\is_string($value) && $value !== '') {
                    /** @var non-empty-string $value */
                    $currentAliases[] = $value;
                }
            }
        }

        $io->write('<info>2/6  aliases</info> — extra paths that mirror the target via');
        $io->write('     symlink (POSIX) or junction (Windows). Reads through any alias');
        $io->write('     see the same files; only the target is physically written.');
        $io->write('     Pick by number, range (1-3), and/or type custom paths.');
        $io->write('');
        foreach (self::COMMON_ALIASES as $i => $path) {
            $marker = \in_array($path, $currentAliases, true) ? '<info>*</info>' : ' ';
            $io->write(\sprintf('     %s %d) %s', $marker, $i + 1, $path));
        }
        $io->write('');

        $defaultPrompt = $currentAliases === []
            ? 'none'
            : self::encodeAliasDefault($currentAliases);

        /** @var mixed $answer */
        $answer = $io->ask(
            \sprintf('  <info>aliases</info> [<comment>%s</comment>]: ', $defaultPrompt),
            $defaultPrompt,
        );

        $parsed = self::parseAliasInput(\is_string($answer) ? $answer : $defaultPrompt, $currentAliases);

        // Validate against target. Re-prompt would be nicer; for now,
        // drop the offending entry with a notice and continue.
        $clean = [];
        $seen = [];
        $targetNorm = \rtrim(\str_replace('\\', '/', $target), '/');
        foreach ($parsed as $alias) {
            $norm = \rtrim(\str_replace('\\', '/', $alias), '/');
            if ($norm === $targetNorm) {
                $io->write(\sprintf(
                    '  <comment>(dropped: %s equals target)</comment>',
                    $alias,
                ));
                continue;
            }
            if (isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $clean[] = $alias;
        }

        $io->write('');

        return $clean;
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return list<non-empty-string>
     */
    private function askTrusted(IOInterface $io, array $defaults): array
    {
        $current = [];
        /** @var mixed $rawTrusted */
        $rawTrusted = $defaults['trusted'] ?? null;
        if (\is_array($rawTrusted)) {
            /** @var mixed $value */
            foreach ($rawTrusted as $value) {
                if (\is_string($value) && $value !== '') {
                    /** @var non-empty-string $value */
                    $current[] = $value;
                }
            }
        }

        $io->write('<info>3/6  trusted</info> — packages allowed to ship skills into this');
        $io->write('     project. Patterns: <comment>vendor/package</comment> (exact) or');
        $io->write('     <comment>vendor/*</comment> (whole vendor). Built-in trust and direct');
        $io->write('     dependencies are implicitly trusted — only list what those');
        $io->write('     two sources do not already cover.');

        $defaultStr = $current === [] ? '<none>' : \implode(',', $current);
        /** @var mixed $answer */
        $answer = $io->ask(
            \sprintf('  <info>trusted</info> (comma-separated) [<comment>%s</comment>]: ', $defaultStr),
            $defaultStr,
        );
        $answer = \is_string($answer) ? \trim($answer) : '';
        if ($answer === '' || $answer === '<none>') {
            $io->write('');
            return $current;
        }

        $out = [];
        foreach (\explode(',', $answer) as $token) {
            $token = \trim($token);
            if ($token !== '') {
                /** @var non-empty-string $token */
                $out[] = $token;
            }
        }
        $io->write('');

        return $out;
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private function askTrustedReplace(IOInterface $io, array $defaults): bool
    {
        $default = (bool) ($defaults['trusted-replace'] ?? false);

        $io->write('<info>4/6  trusted-replace</info> — when <comment>true</comment>, the project trust');
        $io->write('     list <comment>replaces</comment> both the built-in trusted vendors and the');
        $io->write('     implicit direct-dependency trust. Use this for "explicit trust');
        $io->write('     only" mode.');
        $bool = $io->askConfirmation(
            \sprintf(
                '  <info>trusted-replace</info> [<comment>%s</comment>]: ',
                $default ? 'Y/n' : 'y/N',
            ),
            $default,
        );
        $io->write('');

        return $bool;
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private function askDiscovery(IOInterface $io, array $defaults): bool
    {
        $default = (bool) ($defaults['discovery'] ?? false);

        $io->write('<info>5/6  discovery</info> — when <comment>true</comment>, packages without an');
        $io->write('     <comment>extra.skills</comment> block are still considered as donors if');
        $io->write('     they ship a top-level <comment>skills/</comment> directory. Useful for');
        $io->write('     ecosystems where shipping skills is conventional but not yet');
        $io->write('     declared.');
        $bool = $io->askConfirmation(
            \sprintf(
                '  <info>discovery</info> [<comment>%s</comment>]: ',
                $default ? 'Y/n' : 'y/N',
            ),
            $default,
        );
        $io->write('');

        return $bool;
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private function askAutoSync(IOInterface $io, array $defaults): bool
    {
        // Default `true`: most projects want the post-install/update
        // hook to keep skills fresh without ceremony. Users with
        // sensitive CI policies can flip it off here.
        $default = (bool) ($defaults['auto-sync'] ?? true);

        $io->write('<info>6/6  auto-sync</info> — when <comment>true</comment> (default), <comment>skills:update</comment> runs');
        $io->write('     automatically after every <comment>composer install</comment> /');
        $io->write('     <comment>composer update</comment>. Suppressed by <comment>--no-scripts</comment>.');
        $bool = $io->askConfirmation(
            \sprintf(
                '  <info>auto-sync</info> [<comment>%s</comment>]: ',
                $default ? 'Y/n' : 'y/N',
            ),
            $default,
        );
        $io->write('');

        return $bool;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function renderSummary(IOInterface $io, array $result): void
    {
        $io->write('<info>=====================================</info>');
        $io->write('<info>  Summary                            </info>');
        $io->write('<info>=====================================</info>');

        if ($result === []) {
            $io->write('  (defaults across the board — empty skills.json will be written)');
            $io->write('');
            return;
        }

        /** @var mixed $value */
        foreach ($result as $key => $value) {
            $rendered = match (true) {
                \is_bool($value) => $value ? 'true' : 'false',
                \is_array($value) => '[' . \implode(', ', \array_map(
                    static fn(mixed $v): string => \is_string($v) ? $v : (string) \json_encode($v),
                    $value,
                )) . ']',
                default => \is_scalar($value) ? (string) $value : (string) \json_encode($value),
            };
            $io->write(\sprintf('  <comment>%-18s</comment> %s', $key, $rendered));
        }

        $io->write('');
    }
}
