<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Legal\Export;

use DateTimeZone;
use Hilos\Constants\TimeConstants;
use Hilos\Legal\Export\LegalAcceptancesCsv;
use Hilos\Tables\Legal\HilosLegalAcceptanceTableRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The text of a file of acceptance records (HIL-1234): what Excel and Google Sheets open as the table showed it,
 * and nothing a spreadsheet would run.
 */
final class LegalAcceptancesCsvTest extends TestCase
{
    public function testTheFileStartsWithTheUtf8ByteOrderMarkAndTheHeader(): void
    {
        self::assertSame("\xEF\xBB\xBF", LegalAcceptancesCsv::bom());
        self::assertSame("user_id,name,email,document,revision,in_code,accepted_at\r\n", LegalAcceptancesCsv::header());
    }

    public function testALineCarriesTheRowAsTheTableShowsItWithTheMomentInUtc(): void
    {
        self::assertSame(
            "42,Олена Петренко,olena@example.com,terms,2026-09-01,yes,2026-09-27T12:30:05.000+00:00\r\n",
            LegalAcceptancesCsv::line($this->row(name: 'Олена Петренко', email: 'olena@example.com', declared: true), self::utc()),
        );
    }

    public function testInCodeIsNoForARevisionTheCodeDropped(): void
    {
        self::assertStringContainsString(',no,', LegalAcceptancesCsv::line($this->row(declared: false), self::utc()));
    }

    public function testInCodeAndEmailAreEmptyWhenNobodyKnowsThem(): void
    {
        self::assertSame(
            "42,Name,,terms,2026-09-01,,2026-09-27T12:30:05.000+00:00\r\n",
            LegalAcceptancesCsv::line($this->row(email: null, declared: null), self::utc()),
        );
    }

    public function testACellWithAQuoteACommaOrALineBreakIsQuotedAndItsQuotesDoubled(): void
    {
        self::assertSame(
            "42,\"Bob \"\"the\"\", Builder\nJr\",,terms,2026-09-01,yes,2026-09-27T12:30:05.000+00:00\r\n",
            LegalAcceptancesCsv::line($this->row(name: "Bob \"the\", Builder\nJr", email: null), self::utc()),
        );
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function zonesAroundMidnight(): array
    {
        return [
            'east of UTC, into the next day' => [1790548205000, 'Europe/Kyiv', '2026-09-28T01:30:05.000+03:00'],
            'west of UTC, back into the day before' => [1790469005000, 'America/Los_Angeles', '2026-09-26T17:30:05.000-07:00'],
            'a half-hour offset' => [1790535600000, 'Asia/Kolkata', '2026-09-28T00:30:00.000+05:30'],
            'utc' => [1790548205000, TimeConstants::UTC_TIME_ZONE, '2026-09-27T22:30:05.000+00:00'],
            'milliseconds of the moment kept exactly' => [1790512205047, 'Europe/Kyiv', '2026-09-27T15:30:05.047+03:00'],
        ];
    }

    #[DataProvider('zonesAroundMidnight')]
    public function testAcceptedAtIsWrittenInTheZoneOfTheFileWithItsOffset(int $acceptedAt, string $timeZone, string $cell): void
    {
        self::assertSame(
            "42,Name,person@example.com,terms,2026-09-01,yes,{$cell}\r\n",
            LegalAcceptancesCsv::line($this->row(acceptedAt: $acceptedAt), new DateTimeZone($timeZone)),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function formulaStarts(): array
    {
        return [
            'equals' => ['=HYPERLINK("x")', "\"'=HYPERLINK(\"\"x\"\")\""],
            'plus' => ['+1', "'+1"],
            'minus' => ['-1', "'-1"],
            'at' => ['@SUM(A1)', "'@SUM(A1)"],
            'tab' => ["\tname", "'\tname"],
            'carriage return' => ["\rname", "\"'\rname\""],
        ];
    }

    #[DataProvider('formulaStarts')]
    public function testACellAFormulaCouldStartWithGetsAnApostropheInFront(string $name, string $cell): void
    {
        self::assertSame(
            "42,{$cell},,terms,2026-09-01,yes,2026-09-27T12:30:05.000+00:00\r\n",
            LegalAcceptancesCsv::line($this->row(name: $name, email: null), self::utc()),
        );
    }

    public function testAnApostropheInsideANameIsLeftAlone(): void
    {
        self::assertStringStartsWith("42,O'Brien,", LegalAcceptancesCsv::line($this->row(name: "O'Brien"), self::utc()));
    }

    /**
     * @param string $name Name of the person
     * @param ?string $email Verified email, null when there is none
     * @param ?bool $declared Whether the code declares the revision, null when the catalog did not load
     * @param int $acceptedAt Moment of the acceptance in epoch milliseconds
     * @return HilosLegalAcceptanceTableRow Row of the table
     */
    private function row(
        string $name = 'Name',
        ?string $email = 'person@example.com',
        ?bool $declared = true,
        int $acceptedAt = 1790512205000,
    ): HilosLegalAcceptanceTableRow {
        return new HilosLegalAcceptanceTableRow(
            rowKey: 7,
            userId: 42,
            name: $name,
            email: $email,
            document: 'terms',
            revisionId: '2026-09-01',
            declared: $declared,
            acceptedAt: $acceptedAt,
        );
    }

    /**
     * @return DateTimeZone The zone of a file written in UTC
     */
    private static function utc(): DateTimeZone
    {
        return new DateTimeZone(TimeConstants::UTC_TIME_ZONE);
    }
}
