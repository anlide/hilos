<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Pages\DTO\BotPageSubscribeParams;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\PageRouteParams;

/**
 * BotPage - Bot page handler.
 *
 * Handles subscription, unsubscription, and actions for the bot page.
 * Sends the requested bot profile through the browser page payload.
 * A missing, malformed, or unknown `id` surfaces as a structured subscription error.
 *
 * @property BotAgent $agent
 */
final class BotPage extends AbstractPage
{
    public const string PAGE = PageConstants::BOT;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::BOT;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_BOT,
        BrowserConfigKey::PARAMS => [
            BotPageSubscribeParams::BOT_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::GUARDS => [
            [
                BrowserGuardKey::TYPE => BrowserGuardType::DB_EXISTS,
                BrowserGuardKey::SOURCE => ChatBrowserSource::DB_BOTS,
                BrowserGuardKey::KEY => ChatBrowserRef::BOT_ID,
                BrowserGuardKey::ERROR => BrowserSubscriptionError::NOT_FOUND,
            ],
        ],
    ];

    /**
     * Validates the route param DTO before emitting the browser snapshot.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params for the page subscription
     * @throws MissingPageRouteParamException When `id` is absent
     * @throws InvalidPageRouteParamException When `id` is non-numeric or `<= 0`
     * @throws PageSubscriptionException When the browser snapshot rejects the subscription
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        BotPageSubscribeParams::fromPageRouteParams($params);

        parent::onSubscribe($acceptKey, $params);
    }
}
