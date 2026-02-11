<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Database\Idea;
use Demo\Chat\Database\IdeaCollection\Events as IdeaEvents;
use Demo\Chat\DTO\Action\FileActionDTO;
use Demo\Chat\DTO\Action\MessageActionDTO;
use Demo\Chat\DTO\ChatEventSignalDTO;
use Hilos\DTO\Action\ActionPayloadDTO;
use Hilos\DTO\EntitiesChangesDTO;
use Hilos\Logging\Logger\Logger;

/**
 * MainPage - Main chat page handler
 *
 * Handles subscription, unsubscription, and actions for the main chat page.
 */
class MainPage extends AbstractChatPage
{
    /**
     * Get page name
     *
     * @return string Page name
     */
    public function getPageName(): string
    {
        return PageConstants::MAIN;
    }

    /**
     * Handle page-specific subscription logic
     *
     * @param string $acceptKey Accept key
     */
    public function onSubscribe(string $acceptKey): void
    {
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_MAIN,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(
                    full: [
                        Idea::users => Idea::$rt->connections->relevantUsers,
                        Idea::events => Idea::$db->events,
                    ],
                ),
            ),
        );
    }

    /**
     * Handle page-specific unsubscription logic
     *
     * @param string $acceptKey Accept key
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        // nothing special on unsubscribe
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
        switch ($action) {
            case ChatSignalConstants::MESSAGE:
                if ($dto instanceof MessageActionDTO) {
                    $this->handleMessage($acceptKey, $dto);
                }
                break;

            case ChatSignalConstants::FILE:
                if ($dto instanceof FileActionDTO) {
                    $this->handleFile($acceptKey, $dto);
                }
                break;

            default:
                Logger::logAgentError('MainPage', "Unknown action: {$action}");
        }
    }

    /**
     * Handle message action
     *
     * @param string $acceptKey Accept key
     * @param MessageActionDTO $dto Message DTO
     */
    private function handleMessage(string $acceptKey, MessageActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            Logger::logAgentError('MainPage', "Empty message content (acceptKey={$acceptKey})");
            return;
        }

        if (!isset(Idea::$rt->connections[$acceptKey])) {
            Logger::logAgentError('MainPage', "User not found for acceptKey={$acceptKey}");
            return;
        }

        $userId = Idea::$rt->connections[$acceptKey]->userId;
        $event = Idea::$db->events->actions->add(ChatEventType::MESSAGE_SENT->value, $userId, ['message' => $dto->content]);
        $this->getChatAgent()->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(full: [Idea::events => IdeaEvents::fromSingleItem($event)])),
        );
    }

    /**
     * Handle file action
     *
     * @param string $acceptKey Accept key
     * @param FileActionDTO $dto File DTO
     */
    private function handleFile(string $acceptKey, FileActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            Logger::logAgentError('MainPage', "Invalid file data (acceptKey={$acceptKey})");
            return;
        }

        if (!isset(Idea::$rt->connections[$acceptKey])) {
            Logger::logAgentError('MainPage', "User not found for acceptKey={$acceptKey}");
            return;
        }

        $userId = Idea::$rt->connections[$acceptKey]->userId;
        $event = Idea::$db->events->actions->add(ChatEventType::FILE_SHARED->value, $userId, [
            'filename' => $dto->filename,
            'mimeType' => $dto->mimeType,
            'size' => $dto->size,
        ]);
        $this->getChatAgent()->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(full: [Idea::events => IdeaEvents::fromSingleItem($event)])),
        );
    }
}
