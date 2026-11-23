<?php

declare(strict_types=1);

namespace LLM\Skills\Validation\Stage;

use LLM\Skills\Pipeline\ProjectContext;
use LLM\Skills\Pipeline\Stage;
use LLM\Skills\Validation\FindingCollector;

/**
 * Reads the root `composer.json` and publishes its `extra` for later stages. A project
 * without one is valid: its configuration then lives in `skills.json` alone.
 *
 * @internal
 */
final readonly class ComposerJsonStage implements Stage
{
    public const FILE = 'composer.json';

    #[\Override]
    public function run(ProjectContext $context, FindingCollector $findings): void
    {
        $path = (string) $context->projectRoot->join(self::FILE);
        if (!\is_file($path)) {
            return;
        }

        $content = @\file_get_contents($path);
        if ($content === false) {
            $findings->error(self::FILE, null, 'composer-json-unreadable', 'composer.json cannot be read; fix its permissions');

            return;
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($content, false, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $findings->error(self::FILE, null, 'composer-json-invalid', 'composer.json is not valid JSON: ' . $e->getMessage());

            return;
        }
        if (!$decoded instanceof \stdClass) {
            $findings->error(self::FILE, null, 'composer-json-invalid', 'composer.json must hold a JSON object');

            return;
        }

        /** @var array<array-key, mixed> $manifest */
        $manifest = \json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        $context->extra = $manifest['extra'] ?? null;
    }
}
