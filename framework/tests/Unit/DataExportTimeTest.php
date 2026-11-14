<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Hilos\Constants\TimeConstants;
use Hilos\DataExport\DataExportTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Moments of a person's export in the zone chosen for the file (HIL-1579): the stored moment stays the same,
 * only its notation moves, and the offset is always written beside it.
 */
final class DataExportTimeTest extends TestCase
{
    /** 2026-09-27T10:15:30.123Z in epoch milliseconds. */
    private const int MOMENT_MS = 1790504130123;

    /**
     * @param string $sqlDateTime Stored moment, its zone named so the process zone does not matter
     * @param string $timeZone Zone of the file
     * @param string $iso Expected notation
     * @param string $date Expected calendar date for the file name
     */
    #[DataProvider('moments')]
    public function testWritesTheMomentInTheZoneOfTheFile(string $sqlDateTime, string $timeZone, string $iso, string $date): void
    {
        self::assertSame($iso, DataExportTime::iso($sqlDateTime, new DateTimeZone($timeZone)));
        self::assertSame($date, DataExportTime::date($sqlDateTime, new DateTimeZone($timeZone)));
        self::assertSame(strtotime($sqlDateTime), new DateTimeImmutable($iso)->getTimestamp());
    }

    /**
     * @return iterable<string, array{string, string, string, string}> Moments around midnight, offsets and a repeated hour
     */
    public static function moments(): iterable
    {
        yield 'utc keeps its offset' => ['2026-09-27 10:15:30 UTC', 'UTC', '2026-09-27T10:15:30+00:00', '2026-09-27'];
        yield 'before midnight, positive offset moves to the next day' => [
            '2026-03-14 23:30:00 UTC', 'Asia/Kathmandu', '2026-03-15T05:15:00+05:45', '2026-03-15',
        ];
        yield 'before midnight in utc stays on its day' => ['2026-03-14 23:30:00 UTC', 'UTC', '2026-03-14T23:30:00+00:00', '2026-03-14'];
        yield 'after midnight, negative offset moves to the previous day' => [
            '2026-03-15 00:30:00 UTC', 'America/New_York', '2026-03-14T20:30:00-04:00', '2026-03-14',
        ];
        yield 'first of the repeated hour in Warsaw' => [
            '2026-10-25 00:30:00 UTC', 'Europe/Warsaw', '2026-10-25T02:30:00+02:00', '2026-10-25',
        ];
        yield 'second of the repeated hour in Warsaw' => [
            '2026-10-25 01:30:00 UTC', 'Europe/Warsaw', '2026-10-25T02:30:00+01:00', '2026-10-25',
        ];
    }

    /**
     * @param int $milliseconds Stored moment in epoch milliseconds
     * @param string $timeZone Zone of the file
     * @param string $date Expected calendar date for the file name
     */
    #[DataProvider('days')]
    public function testNamesTheFileByTheDayOfTheMomentInTheZoneOfTheFile(int $milliseconds, string $timeZone, string $date): void
    {
        self::assertSame($date, DataExportTime::dateMs($milliseconds, new DateTimeZone($timeZone)));
    }

    /**
     * @return iterable<string, array{int, string, string}> The moments of the notation cases, the last millisecond of a day
     *     and the millisecond before 1970
     */
    public static function days(): iterable
    {
        yield 'utc keeps its day' => [1790504130000, 'UTC', '2026-09-27'];
        yield 'before midnight, positive offset moves to the next day' => [1773531000000, 'Asia/Kathmandu', '2026-03-15'];
        yield 'before midnight in utc stays on its day' => [1773531000000, 'UTC', '2026-03-14'];
        yield 'after midnight, negative offset moves to the previous day' => [1773534600000, 'America/New_York', '2026-03-14'];
        yield 'first of the repeated hour in Warsaw' => [1792888200000, 'Europe/Warsaw', '2026-10-25'];
        yield 'second of the repeated hour in Warsaw' => [1792891800000, 'Europe/Warsaw', '2026-10-25'];
        yield 'the last millisecond of a day in the zone of the file stays on that day' => [1773547199999, 'America/New_York', '2026-03-14'];
        yield 'one millisecond before 1970 is the last day of 1969' => [-1, 'UTC', '1969-12-31'];
    }

    /** An optional moment nobody recorded stays absent instead of becoming a date. */
    public function testAbsentMomentStaysAbsent(): void
    {
        self::assertNull(DataExportTime::iso(null, new DateTimeZone('Asia/Kathmandu')));
    }

    /** Milliseconds survive exactly, and the notation reads back to the same epoch number. */
    public function testKeepsExactMilliseconds(): void
    {
        $iso = DataExportTime::isoMs(self::MOMENT_MS, new DateTimeZone('Asia/Kathmandu'));

        self::assertSame('2026-09-27T16:00:30.123+05:45', $iso);
        self::assertSame('2026-09-27T10:15:30.123+00:00', DataExportTime::isoMs(self::MOMENT_MS, new DateTimeZone('UTC')));
        self::assertSame(self::MOMENT_MS, self::epochMs($iso));
    }

    /** A moment one millisecond before 1970 is not truncated toward zero into the wrong second. */
    public function testOneMillisecondBeforeTheEpoch(): void
    {
        $iso = DataExportTime::isoMs(-1, new DateTimeZone('UTC'));

        self::assertSame('1969-12-31T23:59:59.999+00:00', $iso);
        self::assertSame('1969-12-31T18:59:59.999-05:00', DataExportTime::isoMs(-1, new DateTimeZone('America/New_York')));
        self::assertSame(-1, self::epochMs($iso));
    }

    /**
     * @param string $iso Notation read back
     * @return int Epoch milliseconds of that notation, its seconds floored as the epoch counts them
     */
    private static function epochMs(string $iso): int
    {
        $moment = new DateTimeImmutable($iso);

        return $moment->getTimestamp() * TimeConstants::MS_PER_SECOND + (int) $moment->format('v');
    }
}
