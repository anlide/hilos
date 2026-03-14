<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Collection\Bots;
use Demo\Chat\Hilos;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;

/**
 * BotPage - Bot page handler.
 *
 * Handles subscription, unsubscription, and actions for the bot page.
 * Sends bot profile data on subscribe when bot ID is provided in params.
 */
class BotPage extends AbstractChatPage
{
    /**
     * Get page name.
     *
     * @return string Page name
     */
    public function getPageName(): string
    {
        return PageConstants::BOT;
    }

    /**
     * Handle page-specific subscription logic.
     *
     * @param string $acceptKey Accept key
     * @param array<string, string> $params Route params (e.g. ['id' => botId])
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $entities = $this->getBotEntities($params);
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_BOT,
            $acceptKey,
            new ChatEventSignalDTO($entities),
        );
    }

    /**
     * Build entities DTO for bot page.
     *
     * When params contain valid 'id', returns DTO with single bot in full.bots.
     * Otherwise returns empty DTO.
     *
     * @param array<string, string> $params Route params from page subscription (e.g. ['id' => '123'])
     * @return EntitiesChangesDTO Entity changes for transport (full.bots or empty)
     */
    private function getBotEntities(array $params): EntitiesChangesDTO
    {
        $idParam = $params['id'] ?? null;
        if ($idParam === null || $idParam === '') {
            return new EntitiesChangesDTO();
        }

        $botId = (int) $idParam;
        if ($botId <= 0) {
            return new EntitiesChangesDTO();
        }

        $bot = Hilos::$db->bots[$botId] ?? null;
        if ($bot === null) {
            return new EntitiesChangesDTO();
        }

        $bots = Bots::fromSingleItem($bot);
        return new EntitiesChangesDTO(full: [DbChatContext::bots => $bots]);
    }

    /**
     * Handle page-specific unsubscription logic
     *
     * @param string $acceptKey Accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        // TODO: Implement bot page unsubscribe logic
    }

    /**
     * Handle page-specific action logic
     *
     * @param string $acceptKey Accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        // TODO: Implement bot page action logic
    }
}
