<?php

declare(strict_types=1);

namespace Hilos\Pages\Analytics;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Pages\AbstractHilosAnalyticsPage;

/** People list subscription; the period context belongs to its table query. */
abstract class AbstractHilosAnalyticsPeoplePage extends AbstractHilosAnalyticsPage
{
    public const string PAGE = HilosPageConstants::HILOS_ANALYTICS_PEOPLE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS_PEOPLE,
    ];

    public const array READS_DB = [...parent::READS_DB, HilosDbContext::users];

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Updated route parameters
     * @throws HilosException When the subscription cannot be answered
     */
    final public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * @param string $acceptKey Subscribing connection, unused
     * @param PageRouteParams $params Route parameters, unused
     * @return PagePayload Empty page data, composed with the catalog and table by the subscription path
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        return new PagePayload();
    }
}
