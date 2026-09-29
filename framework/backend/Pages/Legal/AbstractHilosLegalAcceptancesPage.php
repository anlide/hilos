<?php

declare(strict_types=1);

namespace Hilos\Pages\Legal;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\DatabaseException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Pages\Legal\DTO\HilosLegalAcceptanceFiltersSignalData;

/**
 * ADMIN subscription for immutable acceptance records; independent of catalog validity.
 */
abstract class AbstractHilosLegalAcceptancesPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_LEGAL_ACCEPTANCES;
    public const PageReach REACH = PageReach::ROUTE;
    public const array READS_DB = [HilosDbContext::legalAcceptances, HilosDbContext::identities];
    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_ACCEPTANCES,
    ];

    /** @param string $acceptKey Connection leaving this page */
    public function onUnsubscribe(string $acceptKey): void
    {
        LegalAdminAudience::removeSubscriber($acceptKey);
    }

    /**
     * Sends the vocabulary before page_response without consuming a pending broadcast.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route parameters, unused
     * @throws DatabaseException When recorded revision keys cannot be read
     * @throws InvalidArgumentException When the subscription signal cannot be named
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_ACCEPTANCES,
            $acceptKey,
            static::frameForViewer($acceptKey, LegalAdminAudience::filters(), HilosLegalAcceptanceFiltersSignalData::wireFields()),
        );
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route parameters, unused
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        LegalAdminAudience::addSubscriber($acceptKey, static::PAGE);
    }
}
