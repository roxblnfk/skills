<?php

declare(strict_types=1);

namespace LLM\Skills\Validation;

/**
 * How serious a validation finding is. Only {@see self::Error} fails a run.
 */
enum Severity: string
{
    case Error = 'ERROR';
    case Warning = 'WARN';
    case Info = 'INFO';
}
