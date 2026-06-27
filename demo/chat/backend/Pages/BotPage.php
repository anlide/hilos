<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Database\Object\Item\Bot;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\BotPageSubscribeParams;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\PageRouteParams;

/**
 * BotPage - Bot page handler.
 *
 * Handles subscription, unsubscription, and actions for the bot page.
 * Contributes the requested bot's profile as a page-scope entity through
 * buildPagePayload(); the runtime lifecycle status rides the reactive browser
 * snapshot (the `botStatus` data source), so it stays out of the one-shot
 * entity payload. A missing, malformed, or unknown `id` surfaces as a
 * structured subscription error.
 *
 * @property BotAgent $agent
 */
final class BotPage extends AbstractPage
{
    public const string PAGE = PageConstants::BOT;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::BOT;

    /** Page payload entity slot carrying the requested bot's profile. */
    public const string ENTITY_SLOT = 'bot';

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

    /**
     * Builds the bot detail page payload: the requested bot's profile as a
     * page-scope entity the frontend resolves by reference. Returns null when
     * the bot is absent, leaving the browser DB_EXISTS guard to reject the
     * subscription with a structured error.
     *
     * @param PageRouteParams $params Route params for the page subscription
     * @return ?PagePayload Bot profile entity payload, or null when the bot is absent
     * @throws MissingPageRouteParamException When `id` is absent
     * @throws InvalidPageRouteParamException When `id` is non-numeric or `<= 0`
     */
    protected function buildPagePayload(PageRouteParams $params): ?PagePayload
    {
        $botId = BotPageSubscribeParams::fromPageRouteParams($params)->botId;
        if (!isset(Hilos::$db->bots[$botId])) {
            return null;
        }
        $bot = Hilos::$db->bots[$botId];

        return new PagePayload(entities: [
            self::ENTITY_SLOT => [
                Bot::id => $bot->id,
                Bot::name => $bot->name,
                Bot::description => $bot->description,
                Bot::style => $bot->style,
                Bot::topics => $bot->topics,
                Bot::personality => $bot->personality,
                Bot::active => $bot->active,
            ],
        ]);
    }
}
