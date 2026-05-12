<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\View\Collection\Bots;
use Demo\Chat\Frontend\BotFrontendStateProjector;
use Demo\Chat\Hilos;
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
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;

/**
 * BotPage - Bot page handler.
 *
 * Handles subscription, unsubscription, and actions for the bot page.
 * Sends the requested bot profile on subscribe; a missing or malformed `id`
 * surfaces as a structured 400 subscription error.
 *
 * @property BotAgent $agent
 */
final class BotPage extends AbstractPage
{
    public const string PAGE = PageConstants::BOT;

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
        BrowserConfigKey::TABLES => [
            ChatBrowserTable::BOT_DETAIL => [
                BrowserParamKey::PARAMS => [
                    BotPageSubscribeParams::BOT_ID => ChatBrowserRef::BOT_ID,
                ],
            ],
        ],
    ];

    /**
     * Sends the requested bot profile on subscribe.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params for the page subscription
     * @throws MissingPageRouteParamException When `id` is absent
     * @throws InvalidPageRouteParamException When `id` is non-numeric or `<= 0`
     * @throws PageResourceNotFoundException When the bot does not exist
     * @throws ObjectGetIdStringNotImplementedException If Bot object does not implement getIdString()
     * @throws CollectionNotManualException If bots collection is not manual
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $subscribeParams = BotPageSubscribeParams::fromPageRouteParams($params);

        $bot = Hilos::$db->bots[$subscribeParams->botId]
            ?? throw new PageResourceNotFoundException("Bot #{$subscribeParams->botId} not found");

        $this->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_BOT,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(
                    full: [ChatDbContext::bots => Bots::fromSingleItem($bot)],
                ),
                frontend: BotFrontendStateProjector::fullForBots([$bot]),
            ),
        );
    }
}
