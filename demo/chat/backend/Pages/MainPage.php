<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Page\DTO\FileModerationDismissActionDTO;
use Demo\Chat\Core\Page\DTO\FileUploadInitActionDTO;
use Demo\Chat\Core\Page\DTO\MessageActionDTO;
use Demo\Chat\Core\Router\DTO\FileModerationStateUpdateSignalData;
use Demo\Chat\Core\Router\DTO\FileUploadProgressUpdateSignalData;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Core\Router\DTO\ModerationRequestSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Collection\Bots;
use Demo\Chat\Hilos;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;
use Hilos\Utils\Logger;

/**
 * MainPage - Main chat page handler.
 *
 * Handles subscription, unsubscription, and actions for the main chat page.
 */
final class MainPage extends AbstractChatPage
{
    public const string PAGE = PageConstants::MAIN;

    /**
     * Handle page-specific subscription logic.
     *
     * @param string $acceptKey Accept key
     * @param array<string, string> $params Route params from page subscription (unused for main page)
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_MAIN,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(
                    full: [
                        DbChatContext::users => Hilos::$rt->connections->relevantUsers,
                        DbChatContext::bots => $this->getActiveBots(),
                        DbChatContext::events => Hilos::$db->events,
                    ],
                ),
            ),
        );

        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            return;
        }

        $userId = Hilos::$rt->connections[$acceptKey]->userId;
        $moderationState = isset(Hilos::$rt->moderationStates[$userId])
            ? Hilos::$rt->moderationStates[$userId]->message
            : null;
        if ($moderationState !== null) {
            $this->getChatAgent()->sendModerationStateToUserConnections($userId, $moderationState);
        }

        $agent = $this->getChatAgent();
        if ($agent instanceof ChatAgent) {
            $fileUi = $agent->getFileModerationUiPayloadForUser($userId);
            if ($fileUi !== null) {
                $agent->sendToUser(
                    ChatSignalConstants::FILE_MODERATION_STATE_UPDATE,
                    $acceptKey,
                    new FileModerationStateUpdateSignalData($fileUi),
                );
            }
            $fileProgress = $agent->getFileUploadProgressPayloadForUser($userId);
            if ($fileProgress !== null) {
                $agent->sendToUser(
                    ChatSignalConstants::FILE_UPLOAD_PROGRESS_UPDATE,
                    $acceptKey,
                    new FileUploadProgressUpdateSignalData($fileProgress),
                );
            }
        }
    }

    /**
     * Handle page-specific action logic.
     *
     * @param string $acceptKey Accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     * @throws HilosException If database or truth source check fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        switch ($action) {
            case ChatSignalConstants::MESSAGE:
                if ($dto instanceof MessageActionDTO) {
                    $this->handleMessage($acceptKey, $dto);
                }
                break;

            case ChatSignalConstants::FILE_UPLOAD_INIT:
                if ($dto instanceof FileUploadInitActionDTO) {
                    $this->handleFileUploadInit($acceptKey, $dto);
                }
                break;

            case ChatSignalConstants::FILE_MODERATION_DISMISS:
                if ($dto instanceof FileModerationDismissActionDTO) {
                    $this->handleFileModerationDismiss($acceptKey);
                }
                break;

            default:
                Logger::logAgentError('MainPage', "Unknown action: {$action}");
        }
    }

    /**
     * Handle message action.
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

        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            Logger::logAgentError('MainPage', "User not found for acceptKey={$acceptKey}");
            return;
        }

        $userId = Hilos::$rt->connections[$acceptKey]->userId;
        $chatAgent = $this->getChatAgent();
        if ($chatAgent instanceof ChatAgent && !$chatAgent->canSendMessage($userId)) {
            return;
        }

        Hilos::$rt->moderationStates->actions->set($userId, $dto->content);
        $this->getChatAgent()->sendModerationStateToUserConnections($userId, $dto->content);

        $this->getChatAgent()->sendToAgent(
            ChatSignalConstants::MODERATE_REQUEST,
            new ModerationRequestSignalData(
                acceptKey: $acceptKey,
                userId: $userId,
                message: $dto->content,
            ),
        );
    }

    /**
     * Get collection of all active bots (filter: active=true).
     *
     * @return Bots Collection of active bots
     * @throws CollectionNotManualException If Bots collection is not manual (required for filtering)
     * @throws ObjectGetIdStringNotImplementedException If Bot object does not implement getIdString (required for collection operations)
     */
    private function getActiveBots(): Bots
    {
        $collection = Bots::initEmpty();
        foreach (Hilos::$db->bots as $bot) {
            if ($bot->active) {
                $collection->add($bot);
            }
        }
        return $collection;
    }

    private function handleFileUploadInit(string $acceptKey, FileUploadInitActionDTO $dto): void
    {
        $agent = $this->getChatAgent();
        if (!$agent instanceof ChatAgent) {
            return;
        }
        $agent->handleFileUploadInit($acceptKey, $dto);
    }

    private function handleFileModerationDismiss(string $acceptKey): void
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            return;
        }
        $userId = Hilos::$rt->connections[$acceptKey]->userId;
        $agent = $this->getChatAgent();
        if ($agent instanceof ChatAgent) {
            $agent->handleFileModerationDismiss($userId);
        }
    }
}
