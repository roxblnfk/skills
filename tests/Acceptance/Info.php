<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

/**
 * Shared path constants for acceptance tests and their testo infrastructure.
 *
 * Constants are resolved at compile time from this file's location, so they
 * stay correct regardless of the caller's working directory.
 */
final class Info
{
    /**
     * Sandbox consumer project root: `tests/Sandbox/project`.
     *
     * This is where `composer install` runs before acceptance tests; assertions
     * against installed files (e.g. synced skills under `.claude/skills`) should
     * resolve paths relative to this directory.
     */
    public const PROJECT_DIR = __DIR__ . '/../Sandbox/project';

    /**
     * Stub packages directory: `tests/Sandbox/packages`.
     *
     * Each subdirectory follows the `<vendor>/<name>` layout and exposes an
     * `extra.skills.source` block in its `composer.json`. The sandbox project
     * pulls them in via a path repository with a `<vendor>/<name>` glob.
     */
    public const PACKAGES_DIR = __DIR__ . '/../Sandbox/packages';
}
