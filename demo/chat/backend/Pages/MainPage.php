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
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Utils\Logger;
use Random\RandomException;

/**
 * MainPage - Main chat page handler.
 *
 * Handles subscription, unsubscription, and actions for the main chat page.
 * Used only with {@see ChatAgent} (MAIN subscriptions and chat actions are routed to the chat worker).
 */
final class MainPage extends AbstractChatPage
{
    public const string PAGE = PageConstants::MAIN;

    /**
     * Narrows {@see AbstractChatPage::getChatAgent()} to {@see ChatAgent} (see class description).
     */
    protected function getChatAgent(): ChatAgent
    {
        assert($this->agent instanceof ChatAgent);
        return $this->agent;
    }

    /**
     * Handle page-specific subscription logic.
     *
     * @param string $acceptKey Accept key
     * @param array<string, string> $params Route params from page subscription (unused for main page)
     * @throws CollectionNotManualException If Bots collection is not manual (required for filtering)
     * @throws ObjectGetIdStringNotImplementedException If Bot object does not implement getIdString (required for collection operations)
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
     */
    public function onSubscribe(string $acceptKey, array $params = []): void
    {
        $session = $this->getChatAgent()->buildUserSessionSnapshotForAcceptKey($acceptKey);

        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_MAIN,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(
                    full: [
                        DbChatContext::users => Hilos::$rt->connections->relevantUsers,
                        DbChatContext::bots => Bots::fromActiveOnly(),
                        DbChatContext::events => Hilos::$db->events,
                    ],
                ),
                [],
                moderationState: $session['moderationState'],
                fileModerationState: $session['fileModerationState'],
                fileUploadProgress: $session['fileUploadProgress'],
                includeUserSessionFields: true,
            ),
        );
    }

    /**
     * Handle page-specific action logic.
     *
     * @param string $acceptKey Accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     * @throws HilosException If database or truth source check fails
     * @throws RandomException From {@see ChatAgent::handleFileUploadInit}
     * @throws RtActionsCollectionNameNullException From {@see ChatAgent::handleFileUploadInit}
     * @throws RtTruthSourceWriteNotAllowedException From {@see ChatAgent::handleFileUploadInit}
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
                    $this->getChatAgent()->handleFileUploadInit($acceptKey, $dto);
                }
                break;

            case ChatSignalConstants::FILE_MODERATION_DISMISS:
                if ($dto instanceof FileModerationDismissActionDTO) {
                    $this->getChatAgent()->handleFileModerationDismiss($acceptKey);
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
     * @throws RtActionsCallbackNotSetException
     * @throws RtActionsCollectionNameNullException
     * @throws RtActionsStateCollectionNullException
     * @throws RtTruthSourceWriteNotAllowedException
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
        if (!$this->getChatAgent()->canSendMessage($userId)) {
            return;
        }

        Hilos::$rt->userStates->actions->ensure($userId);
        Hilos::$rt->userStates->actions->setTextModerationMessage($userId, $dto->content);
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
}
