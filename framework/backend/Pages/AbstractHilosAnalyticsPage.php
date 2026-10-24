<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Analytics\AnalyticsOverviewSnapshot;
use Hilos\Core\Analytics\AnalyticsSectionReader;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\HilosException;
use Hilos\Pages\Analytics\DTO\HilosAnalyticsOverviewPageSubscribeParams;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * AbstractHilosAnalyticsPage - Abstract base for Hilos analytics page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\AnalyticsPage).
 */
abstract class AbstractHilosAnalyticsPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_ANALYTICS;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS,
    ];

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Updated reader context
     * @throws HilosException When the subscription cannot be answered
     */
    public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * @param string $acceptKey Subscribing connection, unused for this read
     * @param PageRouteParams $params Captured reader context
     * @return PagePayload Complete overview from a single SQL snapshot
     * @throws HilosException When the period is invalid or the analytics read fails
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $route = HilosAnalyticsOverviewPageSubscribeParams::fromPageRouteParams($params);
        $asOfTs = TimeHelper::nowMs();
        if ($route->hourStartTs > $asOfTs) {
            throw new InvalidPageRouteParamException(
                HilosPageRouteParams::HILOS_ANALYTICS_HOUR_START_TS,
                'Choose an analytics period that has already started.',
            );
        }

        return new PagePayload(data: [
            AnalyticsOverviewSnapshot::SECTION => new AnalyticsSectionReader()->overviewSnapshot(
                $route->timeZone, $route->weekStartTs, $route->dayStartTs, $route->hourStartTs, $asOfTs,
            )->toArray(),
        ]);
    }

    /** @return array<string, WireField> Computed aggregates and the subscriber's own context only */
    protected function dataFields(): array
    {
        return [AnalyticsOverviewSnapshot::SECTION => WireField::each([
            AnalyticsOverviewSnapshot::TIME_ZONE => WireField::notPersonal(),
            AnalyticsOverviewSnapshot::WEEK_START_TS => WireField::notPersonal(),
            AnalyticsOverviewSnapshot::DAY_START_TS => WireField::notPersonal(),
            AnalyticsOverviewSnapshot::HOUR_START_TS => WireField::notPersonal(),
            AnalyticsOverviewSnapshot::AS_OF_TS => WireField::notPersonal(),
            AnalyticsOverviewSnapshot::VISITORS_THIS_WEEK => WireField::notPersonal(),
            AnalyticsOverviewSnapshot::VISITORS_TODAY => WireField::notPersonal(),
            AnalyticsOverviewSnapshot::VISITORS_THIS_HOUR => WireField::notPersonal(),
        ])];
    }
}
