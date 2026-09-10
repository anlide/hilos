<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Database\SqlPlaceholders;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for where a statement's `?` placeholders stand (HIL-991).
 *
 * Reading the boundaries of a string literal is a pure function of the text, decided before a
 * statement is assembled and before a connection is involved, which is why it can be asked here
 * rather than of a live database. A live one would answer for something else: half of these texts
 * read differently under `NO_BACKSLASH_ESCAPES`, the very mode the reading here deliberately
 * ignores.
 */
final class SqlPlaceholdersTest extends TestCase
{
    /**
     * The finding itself: the pair of backslashes ending the literal consumes itself, so the
     * parameter behind it is still found.
     */
    public function testAPairOfBackslashesBeforeAClosingQuoteDoesNotSwallowTheParametersAfterIt(): void
    {
        $this->assertSame([29, 53], SqlPlaceholders::positions("SELECT * FROM t WHERE a LIKE ? ESCAPE '\\\\' OR b LIKE ?"));
    }

    /** A literal of nothing but an escaped backslash closes where it is written to close. */
    public function testALiteralHoldingAnEscapedBackslashClosesBeforeTheParameterBehindIt(): void
    {
        $this->assertSame([32], SqlPlaceholders::positions("UPDATE t SET a = '\\\\' WHERE b = ?"));
    }

    /** A doubled quote is a quote of the text and keeps the literal open. */
    public function testADoubledQuoteInsideALiteralDoesNotThrowTheCountOff(): void
    {
        $this->assertSame([42], SqlPlaceholders::positions("SELECT * FROM t WHERE a = 'it''s' AND b = ?"));
    }

    /** Both quote characters open a literal, and each closes the one it opened. */
    public function testALiteralInDoubleQuotesIsReadTheSameWayAsOneInSingleQuotes(): void
    {
        $this->assertSame([39], SqlPlaceholders::positions('SELECT * FROM t WHERE a = "\\\\" AND b = ?'));
    }

    /** Inside a literal, the other quote is an ordinary character. */
    public function testAQuoteOfTheOtherKindInsideALiteralIsAnOrdinaryCharacter(): void
    {
        $this->assertSame([41], SqlPlaceholders::positions("SELECT * FROM t WHERE a = 'it\"s' AND b = ?"));
    }

    /** What the original condition was written for: a single backslash still escapes the quote behind it. */
    public function testAnEscapedQuoteInsideALiteralDoesNotCloseIt(): void
    {
        $this->assertSame([44], SqlPlaceholders::positions("SELECT * FROM t WHERE a = 'it\\'s ?' AND b = ?"));
    }

    /** A question mark of the text is not a placeholder. */
    public function testAQuestionMarkInsideALiteralIsNotAPlaceholder(): void
    {
        $this->assertSame([41], SqlPlaceholders::positions("SELECT * FROM t WHERE a = 'why?' AND b = ?"));
    }

    /** An odd run of backslashes leaves the quote behind it escaped, so the literal runs on. */
    public function testThreeBackslashesBeforeAQuoteLeaveTheLiteralOpen(): void
    {
        $this->assertSame([51], SqlPlaceholders::positions("SELECT * FROM t WHERE a = '\\\\\\' AND b = ?' AND c = ?"));
    }

    /** A statement binding nothing has nothing to report. */
    public function testATextWithoutQuestionMarksGivesAnEmptyList(): void
    {
        $this->assertSame([], SqlPlaceholders::positions('SELECT COUNT(*) FROM t'));
    }

    /** Not a validator of SQL: an unclosed literal is left to the server, and nothing is thrown here. */
    public function testAnUnclosedLiteralKeepsItsQuestionMarksOutOfTheListWithoutAnError(): void
    {
        $this->assertSame([26], SqlPlaceholders::positions("SELECT * FROM t WHERE a = ? AND b = 'x ?"));
    }
}
