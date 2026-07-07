<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Config\Mapper;

use Internal\Path;
use LLM\Skills\Config\Exception\MalformedProjectConfig;
use LLM\Skills\Config\Mapper\ProjectConfigMapper;
use LLM\Skills\Config\ProjectConfig;
use LLM\Skills\Tests\Testo\Filesystem;
use Testo\Assert;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
final class ProjectConfigMapperTest
{
    private string $tmp;

    #[BeforeTest]
    public function setUpTmp(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/llm-skills-mapper-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tmp, 0o777, true);
    }

    #[AfterTest]
    public function tearDownTmp(): void
    {
        Filesystem::removeRecursive($this->tmp);
    }

    public function nullExtraYieldsDefaults(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(null);

        Assert::same($cfg->target, ProjectConfig::DEFAULT_TARGET);
        Assert::same($cfg->trustedReplace, false);
        Assert::same($cfg->trusted->patterns, []);
    }

    public function emptyArrayExtraYieldsDefaults(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra([]);

        Assert::same($cfg->target, ProjectConfig::DEFAULT_TARGET);
    }

    public function missingSkillsBlockYieldsDefaults(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['unrelated' => 'value']);

        Assert::same($cfg->target, ProjectConfig::DEFAULT_TARGET);
        Assert::same($cfg->trusted->patterns, []);
    }

    public function mapsFullValidConfig(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'target' => 'custom/skills',
                'trusted' => ['acme/*', 'foo/bar'],
                'trusted-replace' => true,
            ],
        ]);

        Assert::same($cfg->target, 'custom/skills');
        Assert::same($cfg->trustedReplace, true);
        Assert::same(\count($cfg->trusted->patterns), 2);
        Assert::true($cfg->trusted->trusts('acme/anything'));
        Assert::true($cfg->trusted->trusts('foo/bar'));
        Assert::false($cfg->trusted->trusts('foo/baz'));
    }

    public function defaultsWhenSkillsBlockIsEmpty(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => []]);

        Assert::same($cfg->target, ProjectConfig::DEFAULT_TARGET);
        Assert::same($cfg->trustedReplace, false);
        Assert::same($cfg->trusted->patterns, []);
    }

    public function nonArrayRootExtraThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class);

        (new ProjectConfigMapper())->fromExtra('not-an-array');
    }

    public function skillsBlockWrongTypeThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills');

        (new ProjectConfigMapper())->fromExtra(['skills' => 'string']);
    }

    public function targetEmptyStringThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.target');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['target' => '']]);
    }

    public function targetNonStringThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.target');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['target' => 42]]);
    }

    public function trustedNotAListThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.trusted');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['trusted' => 'acme/*']]);
    }

    public function trustedEmptyStringEntryThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.trusted');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['trusted' => ['']]]);
    }

    public function trustedNonStringEntryThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.trusted');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['trusted' => [42]]]);
    }

    public function trustedBareVendorPatternThrows(): void
    {
        // Bare `vendor` (no slash) is ambiguous and rejected.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.trusted');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['trusted' => ['acme']]]);
    }

    public function trustedReplaceNonBoolThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.trusted-replace');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['trusted-replace' => 'yes']]);
    }

    public function discoveryDefaultsToFalse(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => []]);

        Assert::same($cfg->discovery, false);
    }

    public function discoveryIsMappedWhenTrue(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => ['discovery' => true]]);

        Assert::same($cfg->discovery, true);
    }

    public function discoveryIsMappedWhenExplicitlyFalse(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => ['discovery' => false]]);

        Assert::same($cfg->discovery, false);
    }

    public function discoveryNonBoolThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.discovery');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['discovery' => 'yes']]);
    }

    public function aliasesDefaultToEmptyListWhenAbsent(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => ['target' => 'custom/skills']]);

        Assert::same($cfg->aliases, []);
    }

    public function aliasesDefaultToEmptyListWhenExplicitEmpty(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => ['aliases' => []]]);

        Assert::same($cfg->aliases, []);
    }

    public function mapsAliasesAsConfigured(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'target' => '.agents/skills',
                'aliases' => ['.claude/skills', '.cursor/skills'],
            ],
        ]);

        Assert::same($cfg->aliases, ['.claude/skills', '.cursor/skills']);
    }

    public function aliasesNotAListThrows(): void
    {
        // Associative arrays are rejected — `aliases` is a list-shape only,
        // any keys other than 0..N break the contract.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.aliases');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => ['aliases' => ['one' => '.claude/skills']],
        ]);
    }

    public function aliasesScalarThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.aliases');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['aliases' => '.claude/skills']]);
    }

    public function aliasesNonStringEntryThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.aliases');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['aliases' => [42]]]);
    }

    public function aliasesEmptyStringEntryThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.aliases');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['aliases' => ['']]]);
    }

    public function aliasEqualToTargetThrows(): void
    {
        // An alias of itself is nonsense — caught lexically up front so
        // misconfigurations never reach the planner.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('cannot equal extra.skills.target');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'target' => '.agents/skills',
                'aliases' => ['.agents/skills'],
            ],
        ]);
    }

    public function aliasEqualToTargetIsDetectedAfterLexicalNormalisation(): void
    {
        // Backslash vs forward slash and trailing-slash variants must not
        // sneak past the equality check.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('cannot equal extra.skills.target');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'target' => '.agents/skills',
                'aliases' => ['.agents\\skills/'],
            ],
        ]);
    }

    public function duplicateAliasesThrow(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('duplicates an earlier entry');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'aliases' => ['.claude/skills', '.claude/skills'],
            ],
        ]);
    }

    public function autoSyncDefaultsToTrue(): void
    {
        // Default flipped to true so most projects get the
        // post-install/update freshness behaviour without ceremony.
        // Projects with stricter CI policies opt out via
        // `auto-sync: false`.
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => []]);

        Assert::same($cfg->autoSync, true);
    }

    public function autoSyncIsMappedWhenTrue(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => ['auto-sync' => true]]);

        Assert::same($cfg->autoSync, true);
    }

    public function autoSyncIsMappedWhenExplicitlyFalse(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => ['auto-sync' => false]]);

        Assert::same($cfg->autoSync, false);
    }

    public function autoSyncNonBoolThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.auto-sync');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['auto-sync' => 'yes']]);
    }

    public function pathFromRootDefaultsToNull(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => []]);

        Assert::same($cfg->pathFromRoot, null);
    }

    public function pathFromRootIsMappedWhenSet(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => ['path-from-root' => 'packages/api']]);

        Assert::same($cfg->pathFromRoot, 'packages/api');
    }

    public function pathFromRootNonStringThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.path-from-root');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['path-from-root' => true]]);
    }

    public function pathFromRootAbsoluteThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('must be a relative path');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['path-from-root' => '/abs/api']]);
    }

    public function pathFromRootWithDotDotSegmentThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('plain path segments');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['path-from-root' => '../api']]);
    }

    public function pathFromRootOfPureDotDotAscentThrows(): void
    {
        // path-from-root is a *descent* (where the project sits below the
        // root), not an ascent. A migrating-from-external-target value like
        // `../..` is meaningless here and must be rejected — to climb two
        // levels you declare a two-segment suffix (e.g. `packages/api`).
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('plain path segments');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['path-from-root' => '../..']]);
    }

    public function pathFromRootOfSingleDotThrows(): void
    {
        // "." would otherwise be joined back onto the project root and
        // collapse to it, yielding a no-op suffix. It is a "." segment, so
        // the validator rejects it outright.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('plain path segments');

        (new ProjectConfigMapper())->fromExtra(['skills' => ['path-from-root' => '.']]);
    }

    // ── forProject(): decision tree between skills.json and inline ──────

    public function forProjectFallsBackToInlineWhenSkillsJsonAbsent(): void
    {
        // No skills.json in $tmp; the mapper must use the inline block
        // exactly as fromExtra() would, and report no shadowed keys.
        $resolution = (new ProjectConfigMapper())->forProject(
            Path::create($this->tmp),
            ['skills' => ['target' => 'inline/skills']],
        );

        Assert::same($resolution->config->target, 'inline/skills');
        Assert::same($resolution->ignoredInlineKeys, []);
    }

    public function forProjectLoadsSkillsJsonWhenPresent(): void
    {
        $this->writeSkillsJson(['target' => 'external/skills']);

        $resolution = (new ProjectConfigMapper())->forProject(
            Path::create($this->tmp),
            ['skills' => ['target' => 'inline/skills']],
        );

        Assert::same(
            $resolution->config->target,
            'external/skills',
            'skills.json must win when both sources are present',
        );
    }

    public function forProjectListsShadowedInlineProjectKeys(): void
    {
        // skills.json is present, so inline project keys are shadowed.
        // The caller surfaces this list under -v.
        $this->writeSkillsJson(['target' => 'external/skills']);

        $resolution = (new ProjectConfigMapper())->forProject(
            Path::create($this->tmp),
            ['skills' => [
                'target' => 'inline/skills',
                'aliases' => ['.claude/skills'],
                'auto-sync' => true,
            ]],
        );

        Assert::same($resolution->ignoredInlineKeys, ['target', 'aliases', 'auto-sync']);
    }

    public function forProjectDoesNotListSourceAsShadowed(): void
    {
        // `source` is a donor-side key — the same root package may
        // legitimately ship its own skills via `source` while also
        // being a consumer via skills.json. The warning must not
        // accuse `source` of being shadowed.
        $this->writeSkillsJson(['target' => 'external/skills']);

        $resolution = (new ProjectConfigMapper())->forProject(
            Path::create($this->tmp),
            ['skills' => [
                'source' => 'resources/skills',
                'target' => 'inline/skills',
            ]],
        );

        Assert::same(
            $resolution->ignoredInlineKeys,
            ['target'],
            'source is a donor key and must not appear in the shadowed-keys list',
        );
    }

    public function forProjectEmitsEmptyShadowedListWhenInlineHasOnlyDonorKey(): void
    {
        // skills.json + inline `source` only → nothing is shadowed,
        // because no project-level inline key competes with it.
        $this->writeSkillsJson(['target' => 'external/skills']);

        $resolution = (new ProjectConfigMapper())->forProject(
            Path::create($this->tmp),
            ['skills' => ['source' => 'resources/skills']],
        );

        Assert::same($resolution->ignoredInlineKeys, []);
    }

    public function forProjectPropagatesExternalErrors(): void
    {
        \file_put_contents($this->tmp . '/skills.json', '{ bad json');

        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('skills.json:');

        (new ProjectConfigMapper())->forProject(Path::create($this->tmp), null);
    }

    public function forProjectWithMissingSkillsJsonAndNullExtraReturnsDefaults(): void
    {
        $resolution = (new ProjectConfigMapper())->forProject(Path::create($this->tmp), null);

        Assert::same($resolution->config->target, ProjectConfig::DEFAULT_TARGET);
        Assert::same($resolution->ignoredInlineKeys, []);
    }

    // ── local toggles ──────────────────────────────────────────────────

    public function localDefaultsToEmptyMap(): void
    {
        // Sparse storage: an absent `local` block means "every provider
        // uses its per-provider default" — codified by
        // ProjectConfig::isLocalEnabled() rather than by filling the
        // map up front.
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => []]);

        Assert::same($cfg->local, []);
        Assert::true($cfg->isLocalEnabled('composer'));
        Assert::false($cfg->isLocalEnabled('npm'));
    }

    public function localCanDisableComposer(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra([
            'skills' => ['local' => ['composer' => false]],
        ]);

        Assert::same($cfg->local, ['composer' => false]);
        Assert::false($cfg->isLocalEnabled('composer'));
    }

    public function localUnknownIdThrows(): void
    {
        // Typos must surface as load-time errors — silent ignore would
        // make `loc.composer: false` look like it worked.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('known local provider');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => ['local' => ['githubz' => true]],
        ]);
    }

    public function localNonBoolValueThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.local.composer');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => ['local' => ['composer' => 'yes']],
        ]);
    }

    public function localAsListThrows(): void
    {
        // `local` is a map, not a list — `["composer"]` is wrong shape.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('map of provider-id to boolean');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => ['local' => ['composer']],
        ]);
    }

    // ── remote entries ─────────────────────────────────────────────────

    public function remoteDefaultsToEmptyList(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra(['skills' => []]);

        Assert::same($cfg->sources, []);
    }

    public function remotePackageAdapterParses(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [
                    ['from' => 'github', 'package' => 'acme/skills', 'ref' => '^1.0'],
                ],
            ],
        ]);

        Assert::count($cfg->sources, 1);
        $entry = $cfg->sources[0];
        Assert::same($entry->from, 'github');
        Assert::same($entry->package, 'acme/skills');
        Assert::same($entry->ref, '^1.0');
        Assert::same($entry->url, null);
        Assert::same($entry->host, null);
    }

    public function remoteHostFieldIsPreserved(): void
    {
        // `host` lets users hit GitHub Enterprise / self-hosted GitLab
        // without a new adapter — the same `from` value plus a custom
        // host is enough.
        $cfg = (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [
                    [
                        'from' => 'github',
                        'package' => 'team/skills',
                        'host' => 'https://github.corp.example.com',
                    ],
                ],
            ],
        ]);

        Assert::same($cfg->sources[0]->host, 'https://github.corp.example.com');
    }

    public function remoteUrlAdapterParses(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [
                    ['from' => 'zip', 'url' => 'https://example.com/x.zip', 'sha256' => 'abc'],
                ],
            ],
        ]);

        Assert::count($cfg->sources, 1);
        $entry = $cfg->sources[0];
        Assert::same($entry->from, 'zip');
        Assert::same($entry->url, 'https://example.com/x.zip');
        Assert::same($entry->package, null);
        Assert::same($entry->extras, ['sha256' => 'abc']);
    }

    public function remoteUnknownAdapterThrows(): void
    {
        // `from` vocabulary is locked at the spec table; unknown values
        // must fail at load so a typo never reaches the fetcher (which
        // would otherwise give a less helpful error).
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('not a known source adapter');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [['from' => 'mystery', 'package' => 'acme/x']],
            ],
        ]);
    }

    public function remotePackageRequiredForNameBasedAdapter(): void
    {
        // A `github` entry must use `package`, not `url`.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('package is required');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [['from' => 'github', 'url' => 'https://github.com/acme/x']],
            ],
        ]);
    }

    public function remoteUrlNotAllowedForNameBasedAdapter(): void
    {
        // Conversely, a `github` entry must NOT carry `url`.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('url is not allowed');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [[
                    'from' => 'github',
                    'package' => 'acme/x',
                    'url' => 'https://github.com/acme/x',
                ]],
            ],
        ]);
    }

    public function remoteUrlRequiredForUrlOnlyAdapter(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('url is required');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [['from' => 'zip', 'package' => 'acme/x']],
            ],
        ]);
    }

    public function remotePackageNotAllowedForUrlOnlyAdapter(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('package is not allowed');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [[
                    'from' => 'zip',
                    'url' => 'https://example.com/x.zip',
                    'package' => 'acme/x',
                ]],
            ],
        ]);
    }

    public function remoteDuplicateCompositeKeyThrows(): void
    {
        // Same (from, host, package) triplet ⇒ ambiguous fetch target.
        // We surface it as schema error rather than picking one silently
        // — `skills:add`-style upsert is a separate code path, not part
        // of the mapper.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('duplicates an earlier entry');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [
                    ['from' => 'github', 'package' => 'acme/x'],
                    ['from' => 'github', 'package' => 'acme/x', 'ref' => '^1.0'],
                ],
            ],
        ]);
    }

    public function remoteSameNameWithDifferentHostsCoexist(): void
    {
        // GitHub.com `acme/x` vs corp-GHE `acme/x` are different
        // donors. The composite key includes `host`, so the mapper
        // accepts both.
        $cfg = (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [
                    ['from' => 'github', 'package' => 'acme/x'],
                    ['from' => 'github', 'package' => 'acme/x', 'host' => 'https://github.corp.example.com'],
                ],
            ],
        ]);

        Assert::count($cfg->sources, 2);
    }

    public function remoteSkillsAllowlistIsParsed(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [
                    [
                        'from' => 'github',
                        'package' => 'acme/skills',
                        'skills' => ['code-review', 'refactor'],
                    ],
                ],
            ],
        ]);

        Assert::same($cfg->sources[0]->skills, ['code-review', 'refactor']);
    }

    public function remoteWithoutSkillsKeyDefaultsToNull(): void
    {
        // Absent `skills` key means "sync every skill the donor ships"
        // — represented as `null`, not as an empty list.
        $cfg = (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [
                    ['from' => 'github', 'package' => 'acme/skills'],
                ],
            ],
        ]);

        Assert::same($cfg->sources[0]->skills, null);
    }

    public function remoteSkillsAcceptsEmptyList(): void
    {
        // An empty `skills` list is a deliberate "no skills from this
        // donor" state — the entry stays registered but pulls nothing.
        // Distinct from omitting the key entirely, which keeps the
        // legacy "sync every skill" default.
        $cfg = (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [
                    ['from' => 'github', 'package' => 'acme/skills', 'skills' => []],
                ],
            ],
        ]);

        Assert::same($cfg->sources[0]->skills, []);
    }

    public function remoteSkillsMustBeAList(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('list of skill names');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [
                    ['from' => 'github', 'package' => 'acme/skills', 'skills' => ['name' => true]],
                ],
            ],
        ]);
    }

    public function remoteSkillsRejectsNonStringElement(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('non-empty string');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [
                    ['from' => 'github', 'package' => 'acme/skills', 'skills' => ['ok', '']],
                ],
            ],
        ]);
    }

    public function remoteSkillsKeyIsNotSweptIntoExtras(): void
    {
        // collectExtras must skip `skills` — otherwise it would end up
        // duplicated in both VendorEntry::skills and ::extras.
        $cfg = (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [
                    [
                        'from' => 'github',
                        'package' => 'acme/skills',
                        'skills' => ['hello'],
                    ],
                ],
            ],
        ]);

        Assert::same($cfg->sources[0]->skills, ['hello']);
        Assert::same($cfg->sources[0]->extras, []);
    }

    public function remoteAsObjectThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('list of objects');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => ['remote' => ['github' => 'acme/x']],
        ]);
    }

    public function SourceEntryAsScalarThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('must be an object');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => ['remote' => ['github:acme/x']],
        ]);
    }

    public function remoteRefEmptyStringThrows(): void
    {
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('ref must be a non-empty string');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [['from' => 'github', 'package' => 'acme/x', 'ref' => '']],
            ],
        ]);
    }

    // ── sources: canonical key, `remote` as deprecated alias ────────────

    public function sourcesPackageAdapterParsesLikeRemote(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'sources' => [
                    ['from' => 'github', 'package' => 'acme/skills', 'ref' => '^1.0'],
                ],
            ],
        ]);

        Assert::count($cfg->sources, 1);
        $entry = $cfg->sources[0];
        Assert::same($entry->from, 'github');
        Assert::same($entry->package, 'acme/skills');
        Assert::same($entry->ref, '^1.0');
    }

    public function sourcesUrlAdapterParsesLikeRemote(): void
    {
        $cfg = (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'sources' => [
                    ['from' => 'zip', 'url' => 'https://example.com/x.zip', 'sha256' => 'abc'],
                ],
            ],
        ]);

        Assert::count($cfg->sources, 1);
        $entry = $cfg->sources[0];
        Assert::same($entry->from, 'zip');
        Assert::same($entry->url, 'https://example.com/x.zip');
        Assert::same($entry->extras, ['sha256' => 'abc']);
    }

    public function bothSourcesAndRemoteInlineThrows(): void
    {
        // Both keys in one block is fatal — no merge, no precedence.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills: both "sources" and "remote" are present; keep "sources" only');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'sources' => [['from' => 'github', 'package' => 'acme/x']],
                'remote' => [['from' => 'github', 'package' => 'acme/y']],
            ],
        ]);
    }

    public function bothSourcesAndRemoteInSkillsJsonThrows(): void
    {
        $this->writeSkillsJson([
            'sources' => [['from' => 'github', 'package' => 'acme/x']],
            'remote' => [['from' => 'github', 'package' => 'acme/y']],
        ]);

        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('skills.json: both "sources" and "remote" are present; keep "sources" only');

        (new ProjectConfigMapper())->forProject(Path::create($this->tmp), null);
    }

    public function sourcesErrorMessagesUseSourcesKey(): void
    {
        // A bad entry parsed from `sources` reports the `sources` path.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.sources[0]');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'sources' => [['from' => 'mystery', 'package' => 'acme/x']],
            ],
        ]);
    }

    public function remoteErrorMessagesKeepRemoteKey(): void
    {
        // A bad entry parsed from the legacy `remote` key keeps saying
        // `remote` so the user sees the key they actually wrote.
        Expect::exception(MalformedProjectConfig::class)
            ->withMessageContaining('extra.skills.remote[0]');

        (new ProjectConfigMapper())->fromExtra([
            'skills' => [
                'remote' => [['from' => 'mystery', 'package' => 'acme/x']],
            ],
        ]);
    }

    // ── deprecation flag on ProjectConfigResolution ─────────────────────

    public function forProjectInlineSourcesDoesNotFlagDeprecation(): void
    {
        $resolution = (new ProjectConfigMapper())->forProject(
            Path::create($this->tmp),
            ['skills' => ['sources' => [['from' => 'github', 'package' => 'acme/x']]]],
        );

        Assert::same($resolution->usedDeprecatedSourcesKey, false);
    }

    public function forProjectInlineRemoteFlagsDeprecation(): void
    {
        $resolution = (new ProjectConfigMapper())->forProject(
            Path::create($this->tmp),
            ['skills' => ['remote' => [['from' => 'github', 'package' => 'acme/x']]]],
        );

        Assert::same($resolution->usedDeprecatedSourcesKey, true);
    }

    public function forProjectExternalSourcesDoesNotFlagDeprecation(): void
    {
        $this->writeSkillsJson([
            'sources' => [['from' => 'github', 'package' => 'acme/x']],
        ]);

        $resolution = (new ProjectConfigMapper())->forProject(Path::create($this->tmp), null);

        Assert::same($resolution->usedDeprecatedSourcesKey, false);
    }

    public function forProjectExternalRemoteFlagsDeprecation(): void
    {
        $this->writeSkillsJson([
            'remote' => [['from' => 'github', 'package' => 'acme/x']],
        ]);

        $resolution = (new ProjectConfigMapper())->forProject(Path::create($this->tmp), null);

        Assert::same($resolution->usedDeprecatedSourcesKey, true);
    }

    public function forProjectShadowedInlineKeysIncludeSourcesAndRemote(): void
    {
        // Both the canonical key and its deprecated alias remain
        // project-level keys, so a shadowed inline block reports either.
        $this->writeSkillsJson(['target' => 'external/skills']);

        $resolution = (new ProjectConfigMapper())->forProject(
            Path::create($this->tmp),
            ['skills' => [
                'sources' => [['from' => 'github', 'package' => 'acme/x']],
                'remote' => [['from' => 'github', 'package' => 'acme/y']],
            ]],
        );

        Assert::same($resolution->ignoredInlineKeys, ['sources', 'remote']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeSkillsJson(array $data): void
    {
        \file_put_contents(
            $this->tmp . '/skills.json',
            \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
        );
    }
}
