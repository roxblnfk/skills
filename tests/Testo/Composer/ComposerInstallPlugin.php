<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Testo\Composer;

use Internal\Container\Container;
use Internal\Path;
use Testo\Common\PluginConfigurator;
use Testo\Pipeline\InterceptorCollector;

/**
 * Suite-scoped plugin that wires {@see ComposerInstallInterceptor} into the pipeline.
 *
 * Attach it to the `Acceptance` suite in `testo.php`.
 */
final readonly class ComposerInstallPlugin implements PluginConfigurator
{
    private Path $projectDir;

    public function __construct(
        string $projectDir,
        private bool $cleanup = false,
    ) {
        $this->projectDir = Path::create($projectDir);
    }

    #[\Override]
    public function configure(Container $container): void
    {
        $container->get(InterceptorCollector::class)
            ->addInterceptor(new ComposerInstallInterceptor($this->projectDir, $this->cleanup));
    }
}
