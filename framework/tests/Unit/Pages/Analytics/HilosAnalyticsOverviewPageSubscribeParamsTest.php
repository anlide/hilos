<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Analytics;

use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Pages\AbstractHilosAnalyticsPage;
use Hilos\Pages\Analytics\DTO\HilosAnalyticsBrowserSessionPageSubscribeParams;
use Hilos\Pages\Analytics\DTO\HilosAnalyticsOverviewPageSubscribeParams;
use Hilos\Pages\Analytics\DTO\HilosAnalyticsPersonPageSubscribeParams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Reader context parsing does not acquire domain data or change the detail routes. */
final class HilosAnalyticsOverviewPageSubscribeParamsTest extends TestCase
{
    private const array PARAMS = ['timeZone' => 'UTC', 'weekStartTs' => '100', 'dayStartTs' => '200', 'hourStartTs' => '300'];

    public function testTheFourKeysAndTheirTypedValues(): void
    {
        self::assertSame('timeZone', HilosPageRouteParams::HILOS_ANALYTICS_TIME_ZONE);
        self::assertSame('weekStartTs', HilosPageRouteParams::HILOS_ANALYTICS_WEEK_START_TS);
        self::assertSame('dayStartTs', HilosPageRouteParams::HILOS_ANALYTICS_DAY_START_TS);
        self::assertSame('hourStartTs', HilosPageRouteParams::HILOS_ANALYTICS_HOUR_START_TS);
        foreach (['UTC', 'Europe/Warsaw', 'US/Eastern'] as $zone) {
            $route = HilosAnalyticsOverviewPageSubscribeParams::fromPageRouteParams(
                new PageRouteParams(['timeZone' => $zone] + self::PARAMS),
            );
            self::assertSame($zone, $route->timeZone);
            self::assertSame(100, $route->weekStartTs);
            self::assertSame(200, $route->dayStartTs);
            self::assertSame(300, $route->hourStartTs);
        }
        foreach (['0', '8640000000000000'] as $boundary) {
            $route = HilosAnalyticsOverviewPageSubscribeParams::fromPageRouteParams(new PageRouteParams([
                'timeZone' => 'UTC', 'weekStartTs' => $boundary, 'dayStartTs' => $boundary, 'hourStartTs' => $boundary,
            ]));
            self::assertSame((int)$boundary, $route->hourStartTs);
        }
    }

    /**
     * @param array<string, string> $params Missing or invalid context
     * @param class-string $exception Exact refusal family
     */
    #[DataProvider('invalidContexts')]
    public function testInvalidContext(array $params, string $exception): void
    {
        $this->expectException($exception);
        HilosAnalyticsOverviewPageSubscribeParams::fromPageRouteParams(new PageRouteParams($params));
    }

    /** @return iterable<string, array{array<string, string>, class-string}> Invalid fields and calendar ordering */
    public static function invalidContexts(): iterable
    {
        foreach (array_keys(self::PARAMS) as $key) {
            $params = self::PARAMS;
            unset($params[$key]);
            yield 'missing ' . $key => [$params, MissingPageRouteParamException::class];
            yield 'empty ' . $key => [[$key => ''] + self::PARAMS, MissingPageRouteParamException::class];
        }
        foreach (['weekStartTs', 'dayStartTs', 'hourStartTs'] as $key) {
            foreach (['1.5', 'NaN', 'INF', '1e3', 'abc', '-1', '8640000000000001', '99999999999999999999'] as $value) {
                yield $key . '=' . $value => [[$key => $value] + self::PARAMS, InvalidPageRouteParamException::class];
            }
        }
        foreach (['+02:00', '-0500', 'Invalid/Zone', ' UTC ', 'CEST'] as $zone) {
            yield $zone => [['timeZone' => $zone] + self::PARAMS, InvalidPageRouteParamException::class];
        }
        yield 'week after day' => [['weekStartTs' => '201'] + self::PARAMS, InvalidPageRouteParamException::class];
        yield 'day after hour' => [['dayStartTs' => '301'] + self::PARAMS, InvalidPageRouteParamException::class];
    }

    public function testDetailParamsRemainIndependentOfTheOverview(): void
    {
        self::assertSame(42, HilosAnalyticsPersonPageSubscribeParams::fromPageRouteParams(new PageRouteParams(['userId' => '42']))->userId);
        self::assertSame(7, HilosAnalyticsBrowserSessionPageSubscribeParams::fromPageRouteParams(
            new PageRouteParams(['browserSessionId' => '7']),
        )->browserSessionId);
        $this->expectException(MissingPageRouteParamException::class);
        HilosAnalyticsOverviewPageSubscribeParams::fromPageRouteParams(new PageRouteParams(['userId' => '42', 'browserSessionId' => '7']));
    }

    public function testAFutureHourIsRefusedBeforeAnySqlRead(): void
    {
        $page = new class ($this->createStub(PageAgentInterface::class)) extends AbstractHilosAnalyticsPage {};
        $this->expectException(InvalidPageRouteParamException::class);
        $this->expectExceptionMessage('Choose an analytics period that has already started.');
        $page->onSubscribe('test', new PageRouteParams(['hourStartTs' => '8640000000000000'] + self::PARAMS));
    }
}
