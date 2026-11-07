<?php

declare(strict_types=1);

namespace LLM\Skills\Tests\Unit\Sync;

use LLM\Skills\Sync\SkillConflict;
use LLM\Skills\Tests\Testo\PhpWithoutMbstring;
use Symfony\Component\Process\Process;
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

    /**
     * The polyfill Composer loads defines `mb_convert_case()` without the
     * simple fold mode; only a separate PHP process can run without the extension.
     */
    public function onlyAsciiCaseIsFoldedWhenMbstringIsReplacedByItsPolyfill(): void
    {
        $php = PhpWithoutMbstring::command() ?? throw new SkipTest('mbstring or intl is compiled into PHP');
        $probe = new Process([...$php, '-r', \sprintf(
            <<<'PHP'
                require %s;
                use LLM\Skills\Sync\SkillConflict;
                echo json_encode([
                    function_exists('mb_convert_case'),
                    SkillConflict::foldsUnicode(),
                    SkillConflict::collisionKey('Greeting'),
                    SkillConflict::collisionKey('Über') === SkillConflict::collisionKey('über'),
                ]);
                PHP,
            \var_export(\dirname(__DIR__, 3) . '/vendor/autoload.php', true),
        )]);
        $probe->run();

        Assert::same(
            \json_decode($probe->getOutput(), true),
            [true, false, 'greeting', false],
            $probe->getErrorOutput(),
        );
    }

    public function composedAndDecomposedSpellingsShareAKey(): void
    {
        if (!\class_exists(\Normalizer::class)) {
            throw new SkipTest('intl is not available');
        }

        Assert::same(SkillConflict::collisionKey("\u{00FC}ber"), SkillConflict::collisionKey("u\u{0308}ber"));
    }
}
