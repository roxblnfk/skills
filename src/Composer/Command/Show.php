<?php

declare(strict_types=1);

namespace LLM\Skills\Composer\Command;

use Composer\Command\BaseCommand;
use Internal\Path;
use LLM\Skills\Console\ShowCliDefinition;
use LLM\Skills\Discovery\Provider\DonorProviderBuilder;
use LLM\Skills\Show\ShowRunner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Composer plugin entrypoint for `skills:show` (alias `skills:s`).
 * Registered by {@see \LLM\Skills\Composer\CommandProvider}.
 *
 * Read-only inspection command: lists donor skills, groups them by
 * vendor/package, marks each one as in-sync, drifted, or pending, and
 * appends a `Skipped:` section explaining donors that did not make the
 * main listing.
 *
 * For the PHAR/binary entrypoint that bootstraps Composer manually,
 * see {@see \LLM\Skills\Console\Command\Show}.
 *
 * @internal
 */
final class Show extends BaseCommand
{
    #[\Override]
    protected function configure(): void
    {
        ShowCliDefinition::apply($this, 'skills:show', ['skills:s'], discoveryShortFlag: false);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $options = ShowCliDefinition::buildOptions($input);
        } catch (\InvalidArgumentException $e) {
            $this->getIO()->writeError('<error>[llm/skills] ' . $e->getMessage() . '</error>');
            return self::INVALID;
        }

        $composer = $this->requireComposer();
        $projectRoot = Path::create(\getcwd() ?: '.');
        $extra = $composer->getPackage()->getExtra();
        $provider = (new DonorProviderBuilder())->build($projectRoot, $composer, $extra);

        return (new ShowRunner())->run(
            $projectRoot,
            $provider,
            $extra,
            $this->getIO(),
            $options,
        );
    }
}
