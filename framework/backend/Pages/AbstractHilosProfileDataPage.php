<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\DataExport\DataExportGroup;
use Hilos\DataExport\DataExportStateProjector;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * A reading surface for the signed-in person's data copy (HIL-1177).
 *
 * Ordering belongs to the export agent, not this page. Under impersonation the payload
 * describes the represented person's copy; step-up and download enforce their own refusals.
 */
abstract class AbstractHilosProfileDataPage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_PROFILE_DATA;

    public const PageReach REACH = PageReach::ROUTE;

    /** Signed-in-only surface; see {@see AbstractHilosProfilePage} for why this must stay explicit. */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    /** An exit of a freeze (HIL-945): a frozen person still takes a copy of their own data. */
    public const bool OPEN_WHILE_FROZEN = true;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_PROFILE_DATA,
    ];

    /** @var list<string> The export agent's queue, read for this person's initial state. */
    public const array READS_DB = [HilosDbContext::dataExports];

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route params (unused)
     * @return ?PagePayload Current copy, including absence, or null without a signed-in person
     * @throws HilosException When the person's copy cannot be read
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            return null;
        }

        return new PagePayload(data: [
            DataExportStateProjector::SECTION => DataExportStateProjector::nodeFor(Hilos::$db->dataExports->ofUser($userId)),
        ]);
    }

    /**
     * Joins the person's export group after answering the subscription.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route params (unused)
     * @throws InvalidArgumentException When the join announcement cannot be named
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            return;
        }

        DataExportGroup::join($acceptKey, $userId, $this->getAgentSignalSource());
    }
}
