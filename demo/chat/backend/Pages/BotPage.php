<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\BotAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Collection\Bots;
use Demo\Chat\Frontend\BotFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\BotPageSubscribeParams;
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
                    full: [DbChatContext::bots => Bots::fromSingleItem($bot)],
                ),
                frontend: BotFrontendStateProjector::fullForBots([$bot]),
            ),
        );
    }
}
