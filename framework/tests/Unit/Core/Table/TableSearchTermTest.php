<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\TableSearchTerm;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the one reading of a search term (HIL-821).
 *
 * Both questions this class answers used to be answered separately by each path that searches,
 * and they disagreed: one trimmed the term and another did not, one let a typed percent sign
 * stand for anything and another looked for the sign itself.
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
}
