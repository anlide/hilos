<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Legal\Exception\LegalException;
use Hilos\Legal\LegalCatalogResolver;

/**
 * ADMIN subscription with the legal catalog's refusal and live aggregate windows.
 */
abstract class AbstractHilosLegalPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LEGAL;
    public const PageReach REACH = PageReach::ROUTE;
    public const array READS_DB = [HilosDbContext::legalAcceptances];
    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL,
    ];

    public const string CATALOG_REFUSAL = 'legalCatalogRefusal';

    /**
     * @param string $acceptKey Subscribing connection, unused
     * @param PageRouteParams $params Route parameters, unused
     * @return ?PagePayload Catalog availability; rows come from the page's tables
     * @throws HilosException When a derived legal page cannot read its requested declaration
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        try {
            LegalCatalogResolver::documents();
        } catch (LegalException $e) {
            return new PagePayload(data: [self::CATALOG_REFUSAL => $e->getMessage()]);
        }

        return new PagePayload(data: [self::CATALOG_REFUSAL => null]);
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route parameters, unused
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        LegalAdminAudience::addSubscriber($acceptKey, static::PAGE);
    }

    /** @param string $acceptKey Connection leaving this page */
    public function onUnsubscribe(string $acceptKey): void
    {
        LegalAdminAudience::removeSubscriber($acceptKey);
    }
}
