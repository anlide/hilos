<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\API\Router\HttpRouteTally;
use Hilos\API\Router\HttpRouteTraffic;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\TimeConstants;
use PHPUnit\Framework\TestCase;

/** The hourly ring counts outcomes once and forgets hours outside its window. */
final class HttpRouteTrafficTest extends TestCase
{
    private const string PATH = '/user/{id}';

    public function testStatusAndDurationBoundaries(): void
    {
        $traffic = new HttpRouteTraffic(0);
        foreach ([499, 500, 503, 599, 600] as $status) {
            $traffic->record(HttpConstants::METHOD_GET, self::PATH, $status, 1000, 0);
        }
        $traffic->record(HttpConstants::METHOD_GET, self::PATH, null, 1001, 0);
        $traffic->record(HttpConstants::METHOD_GET, self::PATH, 200, 1400, TimeConstants::SECONDS_PER_HOUR);

        self::assertSame(0, $traffic->countingSince());
        self::assertSame(7, $traffic->revision());
        self::assertEquals(
            new HttpRouteTally(7, 3, 2, 1400),
            $traffic->tally(HttpConstants::METHOD_GET, self::PATH, TimeConstants::SECONDS_PER_HOUR),
        );
        self::assertEquals(new HttpRouteTally(0, 0, 0, null), $traffic->tally(HttpConstants::METHOD_POST, self::PATH, 0));
    }

    public function testWindowDropsTheOldHourAndReusesItsSlot(): void
    {
        $traffic = new HttpRouteTraffic(0);
        $traffic->record(HttpConstants::METHOD_GET, self::PATH, 500, 2001, 0);
        $traffic->record(HttpConstants::METHOD_GET, self::PATH, 200, 11, 23 * TimeConstants::SECONDS_PER_HOUR);

        self::assertEquals(
            new HttpRouteTally(2, 1, 1, 2001),
            $traffic->tally(HttpConstants::METHOD_GET, self::PATH, 23 * TimeConstants::SECONDS_PER_HOUR),
        );
        self::assertEquals(
            new HttpRouteTally(1, 0, 0, 11),
            $traffic->tally(HttpConstants::METHOD_GET, self::PATH, 24 * TimeConstants::SECONDS_PER_HOUR),
        );

        $traffic->record(HttpConstants::METHOD_GET, self::PATH, 200, 22, 24 * TimeConstants::SECONDS_PER_HOUR);
        self::assertEquals(
            new HttpRouteTally(2, 0, 0, 22),
            $traffic->tally(HttpConstants::METHOD_GET, self::PATH, 24 * TimeConstants::SECONDS_PER_HOUR),
        );
    }

    public function testUnroutedCountsHaveTheirOwnRing(): void
    {
        $traffic = new HttpRouteTraffic(0);
        $traffic->recordUnrouted(HttpConstants::HTTP_NOT_FOUND, 0, 0);

        self::assertEquals(new HttpRouteTally(1, 0, 0, 0), $traffic->unroutedTally(0));
        self::assertEquals(new HttpRouteTally(0, 0, 0, null), $traffic->tally(HttpConstants::METHOD_GET, self::PATH, 0));
    }
}
