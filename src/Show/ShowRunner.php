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
 * and the standalone "no donors available" notice) stay here so the
 * builder remains IO-free.
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

        return Command::SUCCESS;
    }
}
