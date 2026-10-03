<?php

declare(strict_types=1);

namespace Hilos\Legal\Export;

use Hilos\DataExport\DataExportTime;
use Hilos\Tables\Legal\HilosLegalAcceptanceTableRow;

/**
 * The text of a file of acceptance records: RFC 4180 with a comma and CRLF, UTF-8 after a byte order mark (HIL-1234).
 *
 * The mark is there for Excel, which reads a CSV without one in the machine's own code page and turns every
 * Cyrillic name into noise. A cell that starts with a character a spreadsheet takes for a formula gets an
 * apostrophe in front: a name is written by the person it belongs to, and the administrator opening the file
 * must not run it.
 */
final class LegalAcceptancesCsv
{
    /** The columns of the file, in order. */
    public const array HEADER = ['user_id', 'name', 'email', 'document', 'revision', 'in_code', 'accepted_at'];

    /** The in_code cell of a revision the code declares. */
    public const string IN_CODE_YES = 'yes';

    /** The in_code cell of a revision the code no longer declares. */
    public const string IN_CODE_NO = 'no';

    private const string BYTE_ORDER_MARK = "\xEF\xBB\xBF";
    private const string SEPARATOR = ',';
    private const string LINE_END = "\r\n";
    private const string QUOTE = '"';
    private const string FORMULA_ESCAPE = "'";

    /** First characters a spreadsheet reads as the start of a formula. */
    private const array FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /** Characters that make a cell quoted. */
    private const array QUOTED_CHARACTERS = [self::SEPARATOR, self::QUOTE, "\r", "\n"];

    /**
     * @return string The bytes the file starts with
     */
    public static function bom(): string
    {
        return self::BYTE_ORDER_MARK;
    }

    /**
     * @return string The header line, its line end included
     */
    public static function header(): string
    {
        return self::lineOf(self::HEADER);
    }

    /**
     * @param HilosLegalAcceptanceTableRow $row One acceptance as the table shows it
     * @return string Its line, the line end included
     */
    public static function line(HilosLegalAcceptanceTableRow $row): string
    {
        return self::lineOf([
            (string) $row->userId,
            $row->name,
            $row->email,
            $row->document,
            $row->revisionId,
            match ($row->declared) {
                true => self::IN_CODE_YES,
                false => self::IN_CODE_NO,
                null => null,
            },
            DataExportTime::iso($row->acceptedAt),
        ]);
    }

    /**
     * @param list<?string> $cells Cell texts of one line, null for a value nobody has
     * @return string The line, the line end included
     */
    private static function lineOf(array $cells): string
    {
        return implode(self::SEPARATOR, array_map(self::cell(...), $cells)) . self::LINE_END;
    }

    /**
     * @param ?string $text Cell text, null for a value nobody has
     * @return string The cell as it is written to the file; an absent value is an empty cell
     */
    private static function cell(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        if ($text !== '' && in_array($text[0], self::FORMULA_STARTS, true)) {
            $text = self::FORMULA_ESCAPE . $text;
        }
        foreach (self::QUOTED_CHARACTERS as $character) {
            if (str_contains($text, $character)) {
                return self::QUOTE . str_replace(self::QUOTE, self::QUOTE . self::QUOTE, $text) . self::QUOTE;
            }
        }

        return $text;
    }
}
