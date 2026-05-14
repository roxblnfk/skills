<?php

declare(strict_types=1);

namespace LLM\Skills\Show;

use Composer\Composer;
use Composer\IO\IOInterface;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\SyncOptions;
use Symfony\Component\Console\Command\Command;

/**
 * Shared body of `skills:show` — independent of which entrypoint invoked it.
 *
 * Mirrors the role {@see \LLM\Skills\Sync\SyncRunner} plays for the
 * update command: built once, fed the same `(Composer, IOInterface,
 * SyncOptions)` triple, returns a Symfony exit code.
 *
 * The runner is intentionally tiny — it composes
 * {@see InspectionBuilder} and {@see ReportFormatter} and routes the
 * resulting lines to IO. No business logic lives here.
 */
final readonly class ShowRunner
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private InspectionBuilder $builder = new InspectionBuilder(),
        private ReportFormatter $formatter = new ReportFormatter(),
    ) {}

    public function run(Composer $composer, IOInterface $io, SyncOptions $options): int
    {
        try {
            $report = $this->builder->build($composer, $options);
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
