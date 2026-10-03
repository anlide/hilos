<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Legal\Export;

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
            "42,Олена Петренко,olena@example.com,terms,2026-09-01,yes,2026-09-27T12:30:05Z\r\n",
            LegalAcceptancesCsv::line($this->row(name: 'Олена Петренко', email: 'olena@example.com', declared: true)),
        );
    }

    public function testInCodeIsNoForARevisionTheCodeDropped(): void
    {
        self::assertStringContainsString(',no,', LegalAcceptancesCsv::line($this->row(declared: false)));
    }

    public function testInCodeAndEmailAreEmptyWhenNobodyKnowsThem(): void
    {
        self::assertSame(
            "42,Name,,terms,2026-09-01,,2026-09-27T12:30:05Z\r\n",
            LegalAcceptancesCsv::line($this->row(email: null, declared: null)),
        );
    }

    public function testACellWithAQuoteACommaOrALineBreakIsQuotedAndItsQuotesDoubled(): void
    {
        self::assertSame(
            "42,\"Bob \"\"the\"\", Builder\nJr\",,terms,2026-09-01,yes,2026-09-27T12:30:05Z\r\n",
            LegalAcceptancesCsv::line($this->row(name: "Bob \"the\", Builder\nJr", email: null)),
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
            "42,{$cell},,terms,2026-09-01,yes,2026-09-27T12:30:05Z\r\n",
            LegalAcceptancesCsv::line($this->row(name: $name, email: null)),
        );
    }

    public function testAnApostropheInsideANameIsLeftAlone(): void
    {
        self::assertStringStartsWith("42,O'Brien,", LegalAcceptancesCsv::line($this->row(name: "O'Brien")));
    }

    /**
     * @param string $name Name of the person
     * @param ?string $email Verified email, null when there is none
     * @param ?bool $declared Whether the code declares the revision, null when the catalog did not load
     * @return HilosLegalAcceptanceTableRow Row of the table
     */
    private function row(string $name = 'Name', ?string $email = 'person@example.com', ?bool $declared = true): HilosLegalAcceptanceTableRow
    {
        return new HilosLegalAcceptanceTableRow(
            rowKey: 7,
            userId: 42,
            name: $name,
            email: $email,
            document: 'terms',
            revisionId: '2026-09-01',
            declared: $declared,
            acceptedAt: '2026-09-27 12:30:05',
        );
    }
}
