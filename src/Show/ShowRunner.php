<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

use Composer\IO\IOInterface;
use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Config\SyncOptions;
use LLM\Skills\Discovery\Provider\DonorProvider;
use Symfony\Component\Console\Command\Command;

/**
 * Shared body of `skills:show` — independent of which entrypoint invoked it.
 *
 * Mirrors the role {@see \LLM\Skills\Sync\SyncRunner} plays for the
 * update command: built once, fed the same project/provider context,
 * returns a Symfony exit code.
 *
 * Project-config IO concerns (the `skills.json` shadowing warning,
 * and the standalone `no donor providers are active` notice) stay
 * here so the builder remains IO-free.
 */
final readonly class ShowRunner
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private InspectionBuilder $builder = new InspectionBuilder(),
        private ReportFormatter $formatter = new ReportFormatter(),
        private ProjectConfigMapper $projectMapper = new ProjectConfigMapper(),
    ) {}

    /**
     * @param Path $projectRoot consumer project root (the entrypoint's cwd)
     * @param DonorProvider $provider source of donors (today
     *        {@see \LLM\Skills\Discovery\Provider\ComposerProvider})
     * @param mixed $extra raw `composer.json` `extra`, or `null` when no
     *        `composer.json` is around (standalone bin run)
     */
    public function run(
        Path $projectRoot,
        DonorProvider $provider,
        mixed $extra,
        IOInterface $io,
        SyncOptions $options,
    ): int {
        try {
            $resolution = $this->projectMapper->forProject($projectRoot, $extra);
        } catch (MalformedProjectConfig $e) {
            $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($resolution->ignoredInlineKeys !== []) {
            $io->writeError(
                '<comment>[warn] skills.json present; the following extra.skills keys in '
                . 'composer.json are ignored: ' . \implode(', ', $resolution->ignoredInlineKeys) . '</comment>',
                verbosity: IOInterface::VERBOSE,
            );
        }

        // See SyncRunner: notice is provider-neutral, specifics flow
        // through `-v` warnings emitted at the entrypoint.
        if (!$provider->isActive($projectRoot)) {
            $io->write(
                '<comment>[llm/skills] no donor providers are active — nothing to show. '
                . 'Run with -v for details.</comment>',
            );
            // Read-only command: never rewrites composer.json. If the
            // user is still on the legacy inline config, point them at
            // the command that will migrate it.
            $this->maybeNotifyLegacyConfig($projectRoot, $io);
            return Command::SUCCESS;
        }

        try {
            $report = $this->builder->build($projectRoot, $provider, $resolution->config, $options);
        } catch (MalformedProjectConfig $e) {
            $io->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        foreach ($this->formatter->format($report) as $line) {
            $io->write($line);
        }

        // Trailing diagnostic — same channel as the existing `[hint]`
        // line emitted by ReportFormatter. Belongs after the report so
        // the user sees the data first and the meta-notice after.
        $this->maybeNotifyLegacyConfig($projectRoot, $io);

        return Command::SUCCESS;
    }

    /**
     * When `skills.json` is absent but inline `extra.skills` carries
     * project keys, emit a one-line hint pointing the user at
     * `skills:update` (which would migrate the block). Quiet on
     * projects that already migrated, or that never had inline
     * config in the first place.
     */
    private function maybeNotifyLegacyConfig(Path $projectRoot, IOInterface $io): void
    {
        $skillsJsonPath = (string) $projectRoot->join('skills.json');
        if (\is_file($skillsJsonPath)) {
            return;
        }

        $composerJsonPath = (string) $projectRoot->join('composer.json');
        if (!\is_file($composerJsonPath)) {
            return;
        }

        $content = \file_get_contents($composerJsonPath);
        if ($content === false) {
            return;
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        if (!\is_array($decoded)) {
            return;
        }
        /** @var array<string, mixed> $extra */
        $extra = \is_array($decoded['extra'] ?? null) ? $decoded['extra'] : [];
        /** @var array<string, mixed> $skills */
        $skills = \is_array($extra['skills'] ?? null) ? $extra['skills'] : [];

        $present = [];
        foreach (\LLM\Skills\Config\Mapper\ProjectConfigMapper::PROJECT_KEYS as $key) {
            if (\array_key_exists($key, $skills)) {
                $present[] = $key;
            }
        }
        if ($present === []) {
            return;
        }

        // Show is invoked from two entrypoints with different command
        // names — `composer skills:update` / `composer skills:init`
        // through the plugin, and `skills update` / `skills init`
        // through the standalone bin. Mention both rather than
        // hard-coding the Composer flavour.
        $io->write(\sprintf(
            '<comment>[notice] legacy inline config detected (extra.skills: %s). '
            . 'Run `composer skills:update` (or `skills update` from the standalone '
            . 'binary) to migrate it into skills.json.</comment>',
            \implode(', ', $present),
        ));
    }
}
