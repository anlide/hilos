<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Core\Analytics\AnalyticsPeriodFilter;
use Hilos\Core\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The raw filter boundary preserves cold context, any time and captured calendar starts. */
final class AnalyticsPeriodFilterTest extends TestCase
{
    private const array CONTEXT = ['timeZone' => 'UTC', 'weekStartTs' => 100, 'dayStartTs' => 200, 'hourStartTs' => 300];

    public function testAbsentContextIsDistinctFromAnyTime(): void
    {
        $cold = AnalyticsPeriodFilter::fromArray([], 400);
        self::assertFalse($cold->hasContext());
        self::assertNull($cold->timeZone);
        self::assertNull($cold->weekStartTs);
        self::assertNull($cold->dayStartTs);
        self::assertNull($cold->hourStartTs);
        self::assertNull($cold->period);
        self::assertNull($cold->startTs());

        $anyTime = AnalyticsPeriodFilter::fromArray(self::CONTEXT, 400);
        self::assertTrue($anyTime->hasContext());
        self::assertSame('UTC', $anyTime->timeZone);
        self::assertSame(100, $anyTime->weekStartTs);
        self::assertSame(200, $anyTime->dayStartTs);
        self::assertSame(300, $anyTime->hourStartTs);
        self::assertNull($anyTime->period);
        self::assertNull($anyTime->startTs());
    }

    public function testPeriodVocabularySelectsTheCapturedLowerBound(): void
    {
        self::assertSame('this-hour', AnalyticsPeriodFilter::THIS_HOUR);
        self::assertSame('today', AnalyticsPeriodFilter::TODAY);
        self::assertSame('this-week', AnalyticsPeriodFilter::THIS_WEEK);
        foreach (['this-hour' => 300, 'today' => 200, 'this-week' => 100] as $period => $start) {
            foreach (['UTC', 'Europe/Warsaw', 'US/Eastern'] as $zone) {
                $filter = AnalyticsPeriodFilter::fromArray(['timeZone' => $zone, 'period' => $period] + self::CONTEXT, 400);
                self::assertSame($zone, $filter->timeZone);
                self::assertSame($period, $filter->period);
                self::assertSame($start, $filter->startTs());
            }
        }
    }

    public function testEqualStartsAndSafeIntegerEndpointsAreAllowed(): void
    {
        foreach ([0, 9007199254740991] as $moment) {
            $filter = AnalyticsPeriodFilter::fromArray([
                'timeZone' => 'UTC', 'weekStartTs' => $moment, 'dayStartTs' => $moment,
                'hourStartTs' => $moment, 'period' => 'this-hour',
            ], $moment);
            self::assertSame($moment, $filter->startTs());
        }
    }

    public function testAReadRechecksTheCurrentServerBound(): void
    {
        $filter = AnalyticsPeriodFilter::fromArray(self::CONTEXT, 400);
        $filter->validateAsOf(300);
        $filter->validateAsOf(500);
        $this->expectException(ValidationException::class);
        $filter->validateAsOf(299);
    }

    /** @param array<string, mixed> $filter Raw partial or invalid filter */
    #[DataProvider('invalidFilters')]
    public function testInvalidFiltersAreRefused(array $filter): void
    {
        $this->expectException(ValidationException::class);
        AnalyticsPeriodFilter::fromArray($filter, 400);
    }

    /** @return iterable<string, array{array<string, mixed>}> Absent required keys, invalid types and calendar bounds */
    public static function invalidFilters(): iterable
    {
        foreach (array_keys(self::CONTEXT) as $key) {
            $missing = self::CONTEXT;
            unset($missing[$key]);
            yield 'missing ' . $key => [$missing];
            yield 'only ' . $key => [[$key => self::CONTEXT[$key]]];
            yield 'null ' . $key => [[$key => null] + self::CONTEXT];
        }
        foreach (['', ' UTC', 'UTC ', 'CEST', '+01:00', 'unknown/zone', false, 1, []] as $index => $zone) {
            yield 'invalid zone ' . $index => [['timeZone' => $zone] + self::CONTEXT];
        }
        foreach (['weekStartTs', 'dayStartTs', 'hourStartTs'] as $key) {
            foreach (['100', 100.0, 100.5, false, [], -1, 9007199254740992, PHP_INT_MAX] as $index => $value) {
                yield $key . ' invalid value ' . $index => [[$key => $value] + self::CONTEXT];
            }
        }
        yield 'week after day' => [['weekStartTs' => 201] + self::CONTEXT];
        yield 'day after hour' => [['dayStartTs' => 301] + self::CONTEXT];
        yield 'hour after server' => [['hourStartTs' => 401] + self::CONTEXT];
        foreach ([null, '', 'any-time', 'this-day', 'Today', 1, false, []] as $index => $period) {
            yield 'invalid period ' . $index => [['period' => $period] + self::CONTEXT];
        }
        yield 'period without context' => [['period' => 'today']];
    }

    /** @param int $asOfTs Invalid server timestamp */
    #[DataProvider('invalidServerBounds')]
    public function testInvalidServerBoundsAreRefusedEvenWithoutContext(int $asOfTs): void
    {
        $this->expectException(ValidationException::class);
        AnalyticsPeriodFilter::fromArray([], $asOfTs);
    }

    /** @return iterable<string, array{int}> Server moments outside the safe nonnegative integer range */
    public static function invalidServerBounds(): iterable
    {
        yield 'negative' => [-1];
        yield 'unsafe' => [9007199254740992];
        yield 'native maximum' => [PHP_INT_MAX];
    }
}
