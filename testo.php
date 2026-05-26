<?php

declare(strict_types=1);

use LLM\Skills\Tests\Acceptance\Info;
use LLM\Skills\Tests\Testo\Composer\ComposerInstallPlugin;
use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\FinderConfig;
use Testo\Application\Config\SuiteConfig;
use Testo\Codecov\CodecovPlugin;
use Testo\Codecov\Config\CoverageLevel;
use Testo\Codecov\Report\CloverReport;
use Testo\Codecov\Report\PhpUnitXmlReport;

return new ApplicationConfig(
    src: new FinderConfig(
        include: ['src'],
    ),
    suites: [
        new SuiteConfig(
            name: 'Unit',
            location: new FinderConfig(
                include: [__DIR__ . '/tests/Unit'],
            ),
        ),
        new SuiteConfig(
            name: 'Feature',
            // Middle ground between Unit and Acceptance: wires real
            // collaborators (filesystem, real mappers, real fetchers)
            // without spinning up Composer's plugin sandbox. Use it
            // when a Unit test would need too many test-doubles to be
            // meaningful, but Acceptance's `composer install` overhead
            // is overkill.
            location: new FinderConfig(
                include: [__DIR__ . '/tests/Feature'],
            ),
        ),
        new SuiteConfig(
            name: 'Acceptance',
            location: new FinderConfig(
                include: [__DIR__ . '/tests/Acceptance'],
            ),
            plugins: [
                new ComposerInstallPlugin(projectDir: Info::PROJECT_DIR, cleanup: false),
            ],
        ),
    ],
    plugins: [
        new CodecovPlugin(
            level: CoverageLevel::Line,
            reports: [
                new CloverReport(__DIR__ . '/runtime/clover.xml', 'Skills'),
                new PhpUnitXmlReport(
                    outputDir: __DIR__ . '/runtime/infection/coverage-xml',
                ),
            ],
        ),
    ],
);
