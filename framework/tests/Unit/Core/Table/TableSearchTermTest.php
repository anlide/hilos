<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\TableSearchMatch;
use Hilos\Core\Table\TableSearchTerm;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the one reading of a search term (HIL-821).
 *
 * Both questions this class answers used to be answered separately by each path that searches,
 * and they disagreed: one trimmed the term and another did not, one let a typed percent sign
 * stand for anything and another looked for the sign itself.
 *
 * A field declared as a mask (HIL-1099) is the one place a typed character stops standing for
 * itself: a star there matches any run, and the term is held against the whole value.
 */
final class TableSearchTermTest extends TestCase
{
    public function testTheEdgesOfATermAreTrimmedAndTheSpaceInsideItIsKept(): void
    {
        self::assertSame('two words', TableSearchTerm::normalize("  two words \n"));
    }

    public function testATermOfNothingButSpacesIsNoTermAtAll(): void
    {
        self::assertNull(TableSearchTerm::normalize('   '));
        self::assertNull(TableSearchTerm::normalize(''));
        self::assertNull(TableSearchTerm::normalize(null));
    }

    public function testAPatternMatchesTheTermAnywhereInTheValue(): void
    {
        self::assertSame('%beta%', TableSearchTerm::likePattern('beta'));
    }

    public function testTheWildcardsATermCarriesAreEscapedIntoThemselves(): void
    {
        // Without this a typed `%` matches every row and a typed `_` matches every neighbour of
        // one, which is the opposite of what somebody typing them is looking for.
        self::assertSame('%50!% !_off%', TableSearchTerm::likePattern('50% _off'));
    }

    public function testTheEscapeCharacterItselfIsEscapedBeforeTheWildcards(): void
    {
        // The escape character is doubled first, so the one escaping the percent sign after it is
        // not doubled a second time and the pattern still says "a bang, then a percent sign".
        self::assertSame('%!!!%%', TableSearchTerm::likePattern('!%'));
    }

    public function testAMaskPatternTurnsEveryStarIntoAnyRunAndHoldsTheWholeValue(): void
    {
        self::assertSame('%-raw.log', TableSearchTerm::likePattern('*-raw.log', TableSearchMatch::Mask));
        self::assertSame('agent-%.log', TableSearchTerm::likePattern('agent-*.log', TableSearchMatch::Mask));
    }

    public function testAMaskPatternStillEscapesTheWildcardsTheTermCarries(): void
    {
        // The escaping runs before the stars turn into `%`, so a typed percent sign stays a sign
        // while the star beside it becomes the wildcard.
        self::assertSame('a!%%', TableSearchTerm::likePattern('a%*', TableSearchMatch::Mask));
    }

    public function testAMaskTermWithNoStarIsStillASubstring(): void
    {
        self::assertSame('%raw%', TableSearchTerm::likePattern('raw', TableSearchMatch::Mask));
    }

    public function testAStarInASubstringFieldStandsForItself(): void
    {
        self::assertSame('%*%', TableSearchTerm::likePattern('*', TableSearchMatch::Substring));
    }

    public function testAMaskMatchesTheWholeValueWhateverItsCase(): void
    {
        $matches = TableSearchTerm::matcher('*-raw.log', TableSearchMatch::Mask);

        self::assertTrue($matches('daemon-raw.log'));
        self::assertTrue($matches('DAEMON-RAW.LOG'));
        self::assertFalse($matches('daemon.log'));
        self::assertFalse($matches('daemon-raw.log.1'), 'the mask holds the end of the value, not a piece of it');
    }

    public function testAStarAtTheEndOfAMaskTakesTheRestOfTheValue(): void
    {
        self::assertTrue(TableSearchTerm::matcher('agent-*', TableSearchMatch::Mask)('agent-x.log'));
    }

    public function testAMaskOfNothingButAStarMatchesAnyValue(): void
    {
        $matches = TableSearchTerm::matcher('*', TableSearchMatch::Mask);

        self::assertTrue($matches('daemon.log'));
        self::assertTrue($matches(''));
    }

    public function testEveryCharacterOfAMaskButTheStarStandsForItself(): void
    {
        // A dot is "any character" to the expression the mask is compared by, so the pieces
        // between the stars have to be quoted for `a.b*` not to find `axb.log`.
        $matches = TableSearchTerm::matcher('a.b*', TableSearchMatch::Mask);

        self::assertFalse($matches('axb.log'));
        self::assertTrue($matches('a.b.log'));
    }

    public function testAStarInASubstringFieldMatchesOnlyAValueCarryingOne(): void
    {
        $matches = TableSearchTerm::matcher('*', TableSearchMatch::Substring);

        self::assertFalse($matches('daemon-raw.log'));
        self::assertTrue($matches('a*b'));
    }
}
