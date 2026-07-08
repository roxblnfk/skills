<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Acceptance;

use JsonSchema\Validator;
use Testo\Assert;
use Testo\Test;

/**
 * Schema-matrix test for `resources/skills.schema.json`.
 *
 * The JSON Schema is hand-written and intended for IDE/editor support.
 * Production code does not validate against it at runtime — the PHP
 * mapper is the authoritative validator. This test guards the two
 * surfaces from drifting apart by replaying a curated set of valid
 * and invalid documents and asserting the schema agrees with what
 * the PHP mapper would do.
 *
 * `justinrainbow/json-schema` is pulled in transitively via
 * `composer/composer` (already required for the plugin) so no new
 * production dependency is added.
 */
#[Test]
final class SkillsSchemaTest
{
    private const SCHEMA_PATH = __DIR__ . '/../../resources/skills.schema.json';

    public function emptyObjectIsAccepted(): void
    {
        $this->assertAccepts([]);
    }

    public function schemaOnlyDocumentIsAccepted(): void
    {
        // The stub `skills:init` writes contains just `$schema`. That
        // must be a valid document under our own schema.
        $this->assertAccepts([
            '$schema' => 'https://raw.githubusercontent.com/roxblnfk/skills/master/resources/skills.schema.json',
        ]);
    }

    public function fullyPopulatedDocumentIsAccepted(): void
    {
        $this->assertAccepts([
            'target' => '.agents/skills',
            'aliases' => ['.claude/skills', '.cursor/skills'],
            'trusted' => ['acme/skills-basic', 'acme/*'],
            'trusted-replace' => true,
            'discovery' => true,
            'auto-sync' => true,
        ]);
    }

    public function sourcesDocumentIsAccepted(): void
    {
        $this->assertAccepts([
            'sources' => [
                ['from' => 'github', 'package' => 'acme/skills'],
                ['from' => 'zip', 'url' => 'https://example.com/skills.zip'],
            ],
        ]);
    }

    public function dirSourceDocumentIsAccepted(): void
    {
        $this->assertAccepts([
            'sources' => [
                ['from' => 'dir', 'path' => './skills'],
                ['from' => 'dir', 'path' => '../shared-skills', 'package' => 'myorg/shared', 'skills' => ['deploy']],
            ],
        ]);
    }

    public function dirSourceMissingPathIsRejected(): void
    {
        // `path` is required for the dir adapter; without it the entry
        // matches none of the oneOf branches.
        $this->assertRejects([
            'sources' => [
                ['from' => 'dir'],
            ],
        ]);
    }

    public function dirSourceWithUrlIsAcceptedBySchemaButRejectedByMapper(): void
    {
        // Matrix note: the schema is deliberately lenient here. A dir
        // entry carrying `url` still matches `sourceByDir` (which does
        // not set additionalProperties:false, mirroring its siblings)
        // and matches no other oneOf branch, so the document validates.
        // The PHP mapper is the authoritative validator and rejects it
        // with `url is not allowed for adapter "dir" (use path)` — see
        // ProjectConfigMapperTest::dirRejectsUrl().
        $this->assertAccepts([
            'sources' => [
                ['from' => 'dir', 'path' => './skills', 'url' => 'https://example.com/x.zip'],
            ],
        ]);
    }

    public function deprecatedRemoteDocumentIsAccepted(): void
    {
        // `remote` is the deprecated alias of `sources`. The schema
        // still accepts it so editors do not flag legacy files before
        // skills:update migrates them.
        $this->assertAccepts([
            'remote' => [
                ['from' => 'github', 'package' => 'acme/skills'],
            ],
        ]);
    }

    public function bothSourcesAndRemoteIsRejected(): void
    {
        // The PHP mapper treats both keys present as fatal; the schema's
        // root `not` clause mirrors that.
        $this->assertRejects([
            'sources' => [['from' => 'github', 'package' => 'acme/skills']],
            'remote' => [['from' => 'github', 'package' => 'acme/other']],
        ]);
    }

    public function unknownTopLevelKeyIsRejected(): void
    {
        $this->assertRejects([
            'target' => '.agents/skills',
            'rogue-key' => 'value',
        ]);
    }

    public function configFileKeyIsRejected(): void
    {
        // `config-file` was considered earlier in design but dropped.
        // The schema must refuse it so editors flag legacy attempts.
        $this->assertRejects([
            'config-file' => 'other.json',
        ]);
    }

    public function emptyTargetIsRejected(): void
    {
        // The PHP mapper rejects an empty `target`; the schema's
        // `minLength: 1` constraint mirrors that.
        $this->assertRejects([
            'target' => '',
        ]);
    }

    public function nonStringTargetIsRejected(): void
    {
        $this->assertRejects([
            'target' => 42,
        ]);
    }

    public function nonBooleanFlagIsRejected(): void
    {
        $this->assertRejects([
            'auto-sync' => 'yes',
        ]);
    }

    public function aliasesAsScalarIsRejected(): void
    {
        $this->assertRejects([
            'aliases' => '.claude/skills',
        ]);
    }

    public function emptyAliasEntryIsRejected(): void
    {
        $this->assertRejects([
            'aliases' => [''],
        ]);
    }

    public function bareVendorPatternIsRejected(): void
    {
        // The mapper rejects `acme` (no slash); the schema mirrors
        // that with a pattern constraint.
        $this->assertRejects([
            'trusted' => ['acme'],
        ]);
    }

    public function emptyTrustedEntryIsRejected(): void
    {
        $this->assertRejects([
            'trusted' => [''],
        ]);
    }

    public function dependenciesBoolFormIsAccepted(): void
    {
        // Short form: a plain enable/disable toggle per manager.
        $this->assertAccepts([
            'dependencies' => [
                'composer' => true,
                'npm' => false,
            ],
        ]);
    }

    public function dependenciesComposerObjectFormIsAccepted(): void
    {
        $this->assertAccepts([
            'dependencies' => [
                'composer' => [
                    'enabled' => true,
                    'trusted' => ['acme/*', 'myorg/skills'],
                    'trusted-replace' => false,
                ],
            ],
        ]);
    }

    public function dependenciesNpmAndGoBlocksAreAccepted(): void
    {
        // npm/go trust patterns validate structurally only — bare names,
        // scoped names and module paths all pass without a pattern.
        $this->assertAccepts([
            'dependencies' => [
                'npm' => [
                    'enabled' => true,
                    'trusted' => ['lodash', '@myorg/pkg', '@myorg/*'],
                ],
                'go' => [
                    'trusted' => ['github.com/myorg/mod', 'github.com/myorg/*'],
                    'trusted-replace' => true,
                ],
            ],
        ]);
    }

    public function dependenciesComposerBadPatternIsRejected(): void
    {
        // Composer's `trusted` carries the vendor/pkg pattern; a bare name
        // matches neither the boolean branch nor the object branch.
        $this->assertRejects([
            'dependencies' => [
                'composer' => ['trusted' => ['acme']],
            ],
        ]);
    }

    public function deprecatedLocalDocumentIsAccepted(): void
    {
        // The legacy `local` toggle map is still a valid document (marked
        // deprecated) so editors do not flag files awaiting migration.
        $this->assertAccepts([
            'local' => ['composer' => true, 'npm' => false],
        ]);
    }

    public function deprecatedTrustedReplaceDocumentIsAccepted(): void
    {
        $this->assertAccepts([
            'trusted-replace' => true,
        ]);
    }

    public function dependenciesWithLegacyTrustedIsRejected(): void
    {
        // Mixing the canonical block with a legacy key is fatal; the
        // root `not` clause mirrors the mapper's "keep dependencies only".
        $this->assertRejects([
            'dependencies' => ['composer' => true],
            'trusted' => ['acme/*'],
        ]);
    }

    public function dependenciesWithLegacyLocalIsRejected(): void
    {
        $this->assertRejects([
            'dependencies' => ['composer' => true],
            'local' => ['composer' => false],
        ]);
    }

    public function dependenciesWithLegacyTrustedReplaceIsRejected(): void
    {
        $this->assertRejects([
            'dependencies' => ['composer' => true],
            'trusted-replace' => true,
        ]);
    }

    public function dependenciesUnknownManagerIdIsRejected(): void
    {
        $this->assertRejects([
            'dependencies' => ['pip' => true],
        ]);
    }

    public function dependenciesUnknownObjectFieldIsRejected(): void
    {
        $this->assertRejects([
            'dependencies' => [
                'composer' => ['enabled' => true, 'rogue-field' => 'x'],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function assertAccepts(array $document): void
    {
        [$valid, $errors] = $this->validate($document);

        Assert::true(
            $valid,
            'document should validate but the schema rejected it. Errors: ' . \implode('; ', $errors),
        );
    }

    /**
     * @param array<string, mixed> $document
     */
    private function assertRejects(array $document): void
    {
        [$valid] = $this->validate($document);

        Assert::false(
            $valid,
            'document should fail validation but the schema accepted it: '
            . \json_encode($document, \JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Run the document through justinrainbow/json-schema. Returns a
     * `[bool $valid, list<string> $errors]` pair.
     *
     * @param array<string, mixed> $document
     *
     * @return array{0: bool, 1: list<string>}
     */
    private function validate(array $document): array
    {
        $schema = \json_decode(
            (string) \file_get_contents(self::SCHEMA_PATH),
            false,
            flags: \JSON_THROW_ON_ERROR,
        );

        // The validator wants stdClass for the root object but plain
        // arrays for JSON arrays inside it (e.g. `aliases`). Encode
        // with normal flags, then decode without assoc to get that
        // mixed shape — and force the root to stdClass via cast,
        // which keeps inner arrays as arrays.
        /** @var object|array $decoded */
        $decoded = \json_decode(
            \json_encode($document, \JSON_THROW_ON_ERROR),
            false,
            flags: \JSON_THROW_ON_ERROR,
        );
        // An empty object decodes to `[]` (PHP empty array) — coerce
        // to stdClass so the schema sees the right type.
        $value = \is_object($decoded) ? $decoded : (object) [];

        $validator = new Validator();
        $validator->validate($value, $schema);

        $errors = [];
        foreach ($validator->getErrors() as $error) {
            $errors[] = \sprintf('%s: %s', $error['property'] ?? '(root)', $error['message'] ?? '');
        }

        return [$validator->isValid(), $errors];
    }
}
