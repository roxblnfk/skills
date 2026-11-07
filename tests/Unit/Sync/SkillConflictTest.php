<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Sync;

use LLM\Skills\Sync\SkillConflict;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Test;

#[Test]
final class SkillConflictTest
{
    public function declarationsNameEveryDifferentSpelling(): void
    {
        $conflict = new SkillConflict(name: 'pdf', packages: ['acme/a', 'acme/b'], names: ['pdf', 'PDF']);

        Assert::same($conflict->declarations(), ['acme/a', 'acme/b (as "PDF")']);
    }

    public function declarationsOfAnExactClashListOnlyThePackages(): void
    {
        $conflict = new SkillConflict(name: 'pdf', packages: ['acme/a', 'acme/b']);

        Assert::same($conflict->declarations(), ['acme/a', 'acme/b']);
    }

    public function distinctNonUtf8NamesKeepDistinctKeys(): void
    {
        Assert::notSame(SkillConflict::collisionKey("caf\xe9"), SkillConflict::collisionKey("caf\xe8"));
        Assert::same(SkillConflict::collisionKey("CAF\xe9"), SkillConflict::collisionKey("caf\xe9"));
    }

    public function asciiNamesDifferingByCaseShareAKey(): void
    {
        Assert::same(SkillConflict::collisionKey('Helper'), SkillConflict::collisionKey('helper'));
    }

    public function distinctNamesKeepDistinctKeys(): void
    {
        Assert::notSame(SkillConflict::collisionKey('helper'), SkillConflict::collisionKey('helpers'));
    }

    public function unicodeNamesDifferingByCaseShareAKey(): void
    {
        if (!\function_exists('mb_convert_case')) {
            throw new SkipTest('mbstring is not available');
        }

        Assert::same(SkillConflict::collisionKey('Über'), SkillConflict::collisionKey('über'));
    }

    public function namesFileSystemsKeepApartKeepDistinctKeys(): void
    {
        if (!\function_exists('mb_convert_case')) {
            throw new SkipTest('mbstring is not available');
        }

        Assert::notSame(SkillConflict::collisionKey('straße'), SkillConflict::collisionKey('strasse'));
    }

    public function unicodeSpellingsShareAKeyWheneverUnicodeIsFolded(): void
    {
        if (!SkillConflict::foldsUnicode()) {
            throw new SkipTest('mbstring or intl is not available');
        }

        Assert::same(SkillConflict::collisionKey("\u{00DC}ber"), SkillConflict::collisionKey("u\u{0308}ber"));
    }

    public function composedAndDecomposedSpellingsShareAKey(): void
    {
        if (!\class_exists(\Normalizer::class)) {
            throw new SkipTest('intl is not available');
        }

        Assert::same(SkillConflict::collisionKey("\u{00FC}ber"), SkillConflict::collisionKey("u\u{0308}ber"));
    }
}
