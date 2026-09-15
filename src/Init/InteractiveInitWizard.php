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
 * Quick mode (`--quick`) runs the same assembly without the questions:
 * every knob takes its default, and the only prompt left is the
 * confirmation at the summary. It is what the plugin offers by itself
 * when it finds an unconfigured project, where a six-question wizard in
 * the middle of `composer update` would be an ambush.
 *
 * Defaults come from one of four sources, in order:
 *
 * 1. Existing `skills.json` (`--force` re-runs surface current
 *    values).
 * 2. Inline `extra.skills` in `composer.json`, when the user
 *    confirms "import current settings" at the first prompt.
 * 3. The agent directories already present in the project, as reported
 *    by {@see AgentWorkspaceProbe}.
 * 4. Built-in defaults from {@see ProjectConfig::default()}.
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
     * @param bool $quick take every answer from `$defaults` without prompting
     *        and go straight to the summary and its single confirmation
     *
     * @return array<string, mixed>|null
     */
    public function run(IOInterface $io, array $defaults, bool $quick = false): ?array
    {
        $io->write('');
        $io->write('<info>=====================================</info>');
        $io->write($quick
            ? '<info>  skills.json — quick setup           </info>'
            : '<info>  skills.json — interactive setup    </info>');
        $io->write('<info>=====================================</info>');
        $io->write('');
        $io->write($quick
            ? 'Every value below was picked for you. Confirm to write them, '
                . 'or answer <comment>n</comment> and run '
                . '<comment>skills:init</comment> to set them one by one.'
            : 'Press <comment>Enter</comment> to accept the default in [brackets]. '
                . 'Ctrl+C aborts.');
        $io->write('');

        $target = $quick ? $this->resolveTarget($defaults) : $this->askTarget($io, $defaults);
        $aliases = $quick
            ? $this->cleanAliases($io, $this->resolveAliases($defaults), $target)
            : $this->askAliases($io, $defaults, $target);
        $trusted = $quick ? $this->resolveTrusted($defaults) : $this->askTrusted($io, $defaults);
        $trustedReplace = $this->resolveTrustedReplace($defaults);
        // Replacing the trust list only means something when there is a list
        // to replace it with; asking about it next to an empty `trusted`
        // spends a question on a no-op.
        if (!$quick && $trusted !== []) {
            $trustedReplace = $this->askTrustedReplace($io, $defaults);
        }
        $autoSync = $quick ? $this->resolveAutoSync($defaults) : $this->askAutoSync($io, $defaults);

        // Seed from the (already normalised) defaults so every key the
        // wizard does NOT prompt for — `sources`, `path-from-root`, other
        // managers' `dependencies` entries, `dependencies.composer.enabled`
        // — survives a `--force` rewrite verbatim. The prompted answers
        // below override only the keys the wizard owns.
        $result = $defaults;

        // Prompted keys are compared against their canonical default: an
        // answer equal to the default stays un-emitted (dropping any seeded
        // copy), tracking DEFAULT_TARGET in one place. A non-default answer
        // overrides the seed.
        if ($target !== ProjectConfig::DEFAULT_TARGET) {
            $result['target'] = $target;
        } else {
            unset($result['target']);
        }
        if ($aliases !== []) {
            $result['aliases'] = $aliases;
        } else {
            unset($result['aliases']);
        }

        // Trust answers land under `dependencies.composer`; the wizard owns
        // only `trusted` / `trusted-replace` there. `enabled` and sibling
        // manager entries (`npm`, `go`) are preserved by merging at the
        // composer-object / dependencies-map level rather than rebuilding
        // from scratch.
        $this->applyComposerTrust($result, $trusted, $trustedReplace);

        // auto-sync's default is `true`; only emit the key when the user
        // opted out, otherwise let the default carry it.
        if (!$autoSync) {
            $result['auto-sync'] = false;
        } else {
            unset($result['auto-sync']);
        }

        // Emit in PROJECT_KEYS order so generated skills.json files are
        // diff-stable regardless of the seeded defaults' key order.
        $result = $this->orderProjectKeys($result);

        $this->renderSummary($io, $result);

        if (!$io->askConfirmation('<info>Write skills.json? [Y/n]:</info> ', true)) {
            $io->write('<comment>[init] aborted by user.</comment>');
            return null;
        }

        return $result;
    }

    /**
     * Drop aliases that cannot stand as one: an alias equal to the target
     * would have sync link a directory onto itself, and a duplicate would
     * have it create the same link twice. Comparison is lexical — a
     * separator-only or trailing-slash difference is the same path.
     *
     * @param list<non-empty-string> $aliases
     * @param non-empty-string $target
     *
     * @return list<non-empty-string>
     */
    public function cleanAliases(IOInterface $io, array $aliases, string $target): array
    {
        $clean = [];
        $seen = [];
        $targetNorm = \rtrim(\str_replace('\\', '/', $target), '/');
        foreach ($aliases as $alias) {
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

        return $clean;
    }

    /**
     * The `composer` entry of a `dependencies` defaults block, as an
     * object. Defaults reach the wizard from a migrated `skills.json`
     * (or a folded inline block), both of which carry trust under
     * `dependencies.composer` rather than the flat legacy keys. A short
     * `"composer": true` toggle carries no trust fields, so it maps to
     * an empty object here.
     *
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     *
     * @psalm-pure
     */
    private static function composerDependencyDefault(array $defaults): array
    {
        /** @var mixed $dependencies */
        $dependencies = $defaults['dependencies'] ?? null;
        if (!\is_array($dependencies)) {
            return [];
        }
        /** @var mixed $composer */
        $composer = $dependencies['composer'] ?? null;

        /** @var array<string, mixed> */
        return \is_array($composer) ? $composer : [];
    }

    /**
     * Build the `dependencies.composer` entry from the inherited default
     * and the wizard-owned trust knobs, in canonical field order
     * (`enabled`, `trusted`, `trusted-replace`). Composer defaults to
     * enabled, so only an explicit `enabled: false` is carried across; a
     * `true` or absent flag is the default and stays unwritten. Returns
     * `null` when the entry would hold nothing.
     *
     * @param list<non-empty-string> $trusted
     *
     * @return array<string, mixed>|null
     *
     * @psalm-pure
     */
    private static function mergeComposerTrust(mixed $default, array $trusted, bool $trustedReplace): ?array
    {
        // A bare bool toggle carries only `enabled`; an object carries it
        // under the `enabled` field. Either way, only `false` is meaningful
        // to preserve — it flips composer off its enabled-by-default state.
        /** @var mixed $rawEnabled */
        $rawEnabled = \is_array($default) ? ($default['enabled'] ?? null) : $default;

        $composer = [];
        if ($rawEnabled === false) {
            $composer['enabled'] = false;
        }
        if ($trusted !== []) {
            $composer['trusted'] = $trusted;
        }
        if ($trustedReplace) {
            $composer['trusted-replace'] = true;
        }

        return $composer === [] ? null : $composer;
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
     * Overlay the wizard-owned `trusted` / `trusted-replace` knobs onto the
     * seeded `dependencies.composer` entry, writing the merged map back into
     * `$result`. Sibling manager entries (`npm`, `go`) and composer's own
     * `enabled` flag survive because the merge happens at the composer-object
     * / dependencies-map level rather than rebuilding from the prompts alone.
     * An emptied composer entry, and an emptied `dependencies` block, are
     * dropped — the only content that can empty them is the wizard-owned
     * trust the user just cleared.
     *
     * @param array<string, mixed> $result
     * @param list<non-empty-string> $trusted
     */
    private function applyComposerTrust(array &$result, array $trusted, bool $trustedReplace): void
    {
        /** @var array<string, mixed> $dependencies */
        $dependencies = \is_array($result[ProjectConfigMapper::DEPENDENCIES_KEY] ?? null)
            ? $result[ProjectConfigMapper::DEPENDENCIES_KEY]
            : [];
        /** @var mixed $composerDefault */
        $composerDefault = $dependencies['composer'] ?? null;

        $composer = self::mergeComposerTrust($composerDefault, $trusted, $trustedReplace);
        if ($composer === null) {
            unset($dependencies['composer']);
        } else {
            $dependencies['composer'] = $composer;
        }

        if ($dependencies === []) {
            unset($result[ProjectConfigMapper::DEPENDENCIES_KEY]);
        } else {
            $result[ProjectConfigMapper::DEPENDENCIES_KEY] = $dependencies;
        }
    }

    /**
     * Re-order a project-keys map to {@see ProjectConfigMapper::PROJECT_KEYS}
     * order for diff-stable output; keys outside that list are dropped.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     *
     * @psalm-pure
     */
    private function orderProjectKeys(array $values): array
    {
        $out = [];
        foreach (ProjectConfigMapper::PROJECT_KEYS as $key) {
            if (\array_key_exists($key, $values)) {
                /** @psalm-suppress MixedAssignment */
                $out[$key] = $values[$key];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return non-empty-string
     *
     * @psalm-pure
     */
    private function resolveTarget(array $defaults): string
    {
        /** @var mixed $rawDefault */
        $rawDefault = $defaults['target'] ?? null;

        return \is_string($rawDefault) && $rawDefault !== ''
            ? $rawDefault
            : ProjectConfig::DEFAULT_TARGET;
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return non-empty-string
     */
    private function askTarget(IOInterface $io, array $defaults): string
    {
        $default = $this->resolveTarget($defaults);

        $io->write('<info>1/4  target</info> — destination directory for synced skills,');
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
     * @param array<string, mixed> $defaults
     *
     * @return list<non-empty-string>
     *
     * @psalm-pure
     */
    private function resolveAliases(array $defaults): array
    {
        $current = [];
        /** @var mixed $rawAliases */
        $rawAliases = $defaults['aliases'] ?? null;
        if (\is_array($rawAliases)) {
            /** @var mixed $value */
            foreach ($rawAliases as $value) {
                if (\is_string($value) && $value !== '') {
                    /** @var non-empty-string $value */
                    $current[] = $value;
                }
            }
        }

        return $current;
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
        $currentAliases = $this->resolveAliases($defaults);

        $io->write('<info>2/4  aliases</info> — extra paths that mirror the target via');
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

        $clean = $this->cleanAliases($io, $parsed, $target);
        $io->write('');

        return $clean;
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return list<non-empty-string>
     *
     * @psalm-pure
     */
    private function resolveTrusted(array $defaults): array
    {
        $current = [];
        /** @var mixed $rawTrusted */
        $rawTrusted = self::composerDependencyDefault($defaults)['trusted'] ?? null;
        if (\is_array($rawTrusted)) {
            /** @var mixed $value */
            foreach ($rawTrusted as $value) {
                if (\is_string($value) && $value !== '') {
                    /** @var non-empty-string $value */
                    $current[] = $value;
                }
            }
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return list<non-empty-string>
     */
    private function askTrusted(IOInterface $io, array $defaults): array
    {
        $current = $this->resolveTrusted($defaults);

        $io->write('<info>3/4  trusted</info> — packages allowed to ship skills into this');
        $io->write('     project. Patterns: <comment>vendor/package</comment> (exact) or');
        $io->write('     <comment>vendor/*</comment> (whole vendor). Built-in trust and direct');
        $io->write('     dependencies are implicitly trusted — only list what those');
        $io->write('     two sources do not already cover. Type <comment>&lt;none&gt;</comment>');
        $io->write('     to clear the list.');

        $defaultStr = $current === [] ? '<none>' : \implode(',', $current);
        /** @var mixed $answer */
        $answer = $io->ask(
            \sprintf('  <info>trusted</info> (comma-separated) [<comment>%s</comment>]: ', $defaultStr),
            $defaultStr,
        );
        $answer = \is_string($answer) ? \trim($answer) : '';

        // Three branches:
        // - empty answer       → user pressed Enter; keep current list as-is.
        // - literal "<none>"   → user explicitly cleared. When the default was
        //                       already "<none>" (empty current), this collapses
        //                       to the same empty result naturally.
        // - everything else    → parse comma-separated tokens.
        if ($answer === '') {
            $io->write('');
            return $current;
        }
        if ($answer === '<none>') {
            $io->write('');
            return [];
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
     *
     * @psalm-pure
     */
    private function resolveTrustedReplace(array $defaults): bool
    {
        return (bool) (self::composerDependencyDefault($defaults)['trusted-replace'] ?? false);
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private function askTrustedReplace(IOInterface $io, array $defaults): bool
    {
        $default = $this->resolveTrustedReplace($defaults);

        $io->write('     <info>trusted-replace</info> — when <comment>true</comment>, the list above');
        $io->write('     <comment>replaces</comment> both the built-in trusted vendors and the');
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
     *
     * @psalm-pure
     */
    private function resolveAutoSync(array $defaults): bool
    {
        // Default `true`: most projects want the post-install/update
        // hook to keep skills fresh without ceremony. Users with
        // sensitive CI policies can flip it off here.
        return (bool) ($defaults['auto-sync'] ?? true);
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private function askAutoSync(IOInterface $io, array $defaults): bool
    {
        $default = $this->resolveAutoSync($defaults);

        $io->write('<info>4/4  auto-sync</info> — when <comment>true</comment> (default), <comment>skills:update</comment> runs');
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
