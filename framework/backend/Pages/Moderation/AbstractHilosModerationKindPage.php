<?php

declare(strict_types=1);

namespace Hilos\Pages\Moderation;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Moderation\DTO\HilosModerationKindSubscribeParams;
use Hilos\Tables\Moderation\HilosModerationKindsTable;
use Hilos\Tables\Moderation\HilosModerationKindTableRow;

/** Read-only overview of one declared kind; projects supply only its subscription agent. */
abstract class AbstractHilosModerationKindPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_MODERATION_KIND;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MODERATION_KIND,
    ];

    /**
     * Revalidates the new address and answers with the complete page.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Merged route parameters
     * @throws HilosException When admission or the page response fails
     */
    final public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * The URL owns this window, including on cold entry and reconnect. A list window reported
     * by the client must not replace it before the browser snapshot is built.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Raw route parameters
     * @throws MissingPageRouteParamException When kind is missing or empty
     * @throws PageResourceNotFoundException When the directory does not publish the kind
     * @throws InvalidArgumentException When the project's kind directory is malformed
     */
    final protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $address = HilosModerationKindSubscribeParams::fromPageRouteParams($params);
        if (!isset(Hilos::moderationKinds()[$address->kind])) {
            throw new PageResourceNotFoundException('Unknown moderation kind: ' . $address->kind);
        }

        Hilos::$sr?->takeReportedTableWindows($acceptKey);
        Hilos::$sr?->setTableViewport($acceptKey, new TableViewportSubscription(
            tableKey: HilosModerationKindsTable::TABLE,
            filter: [HilosModerationKindsTable::FILTER_KIND => $address->kind],
            limit: 1,
            rendered: [
                HilosModerationKindTableRow::kind,
                HilosModerationKindTableRow::title,
                HilosModerationKindTableRow::owner,
                HilosModerationKindTableRow::input,
                HilosModerationKindTableRow::model,
                HilosModerationKindTableRow::ownRuleCount,
                HilosModerationKindTableRow::profile,
                HilosModerationKindTableRow::reasons,
            ],
        ));
    }
}
