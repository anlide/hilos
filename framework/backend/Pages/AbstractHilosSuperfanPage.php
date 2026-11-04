<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Agent\Hilos\AbstractHilosSuperfanAgent;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Pages\DTO\HilosSuperfanPageSubscribeParams;

/**
 * AbstractHilosSuperfanPage - Abstract base for Hilos superfan page.
 *
 * Projects must implement concrete class (e.g. Demo\Chat\Pages\Hilos\Superfan\SuperfanPage).
 * Parses the `agentId` route param before sending the browser snapshot.
 *
 * @property AbstractHilosSuperfanAgent $agent
 */
abstract class AbstractHilosSuperfanPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_SUPERFAN;

    public const PageReach REACH = PageReach::ROUTE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_SUPERFAN,
    ];

    /**
     * Parses route params into {@see HilosSuperfanPageSubscribeParams} before the page is
     * answered, so a malformed id refuses the subscription instead of being answered and then
     * failing. Final: subclasses customize page state through browser config.
     *
     * @param string $acceptKey WebSocket accept key (unused; the parse reads params only)
     * @param PageRouteParams $params Route params for the page subscription
     * @throws MissingPageRouteParamException When `agentId` is absent or empty
     * @throws InvalidPageRouteParamException Reserved for future typed constraints on the id
     */
    final protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        HilosSuperfanPageSubscribeParams::fromPageRouteParams($params);
    }
}
