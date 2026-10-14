<?php

declare(strict_types=1);

namespace Hilos\Pages\Analytics;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Analytics\AnalyticsSectionReader;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\HilosException;
use Hilos\Pages\AbstractHilosAnalyticsPage;
use Hilos\Pages\Analytics\DTO\HilosAnalyticsBrowserSessionPageSubscribeParams;

/** ADMIN subscription carrying one browser-session summary in its opening response. */
abstract class AbstractHilosAnalyticsBrowserSessionPage extends AbstractHilosAnalyticsPage
{
    public const string PAGE = HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSION;
    public const string SECTION = 'browserSession';
    public const string FIELD_ID = 'id';
    public const string FIELD_BROWSER = 'browser';
    public const string FIELD_LAST_SIGNED_IN_USER_ID = 'lastSignedInUserId';
    public const string FIELD_LAST_SIGNED_IN_USER_LABEL = 'lastSignedInUserLabel';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS_BROWSER_SESSION,
    ];

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
     * @param PageRouteParams $params Raw route parameters
     * @throws MissingPageRouteParamException When the session number is absent or empty
     * @throws InvalidPageRouteParamException When the session number is not a positive integer
     */
    final protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        HilosAnalyticsBrowserSessionPageSubscribeParams::fromPageRouteParams($params);
    }

    /**
     * @param string $acceptKey Subscribing connection, unused for the shared summary
     * @param PageRouteParams $params Raw route parameters
     * @return PagePayload Current browser-session summary and personal page label
     * @throws HilosException When the route is invalid, the session is missing, or its lookup fails
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $route = HilosAnalyticsBrowserSessionPageSubscribeParams::fromPageRouteParams($params);
        $session = new AnalyticsSectionReader()->browserSession($route->browserSessionId);
        if ($session === null) {
            throw new PageResourceNotFoundException('No such analytics browser session');
        }

        return new PagePayload(data: [
            PageCatalogConstants::WIRE_PAGE_LABEL => 'Browser session #' . $session->id,
            self::SECTION => [
                self::FIELD_ID => $session->id,
                self::FIELD_BROWSER => $session->browser,
                self::FIELD_LAST_SIGNED_IN_USER_ID => $session->lastSignedInUserId,
                self::FIELD_LAST_SIGNED_IN_USER_LABEL => $session->lastSignedInUserLabel,
            ],
        ]);
    }
}
