<?php

declare(strict_types=1);

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
