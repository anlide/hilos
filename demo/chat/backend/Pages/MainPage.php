<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Pages\Main\UploadFileTrait;
use Demo\Chat\Core\Page\DTO\FileModerationDismissActionDTO;
use Demo\Chat\Core\Page\DTO\FileUploadInitActionDTO;
use Demo\Chat\Core\Page\DTO\MessageActionDTO;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Core\Router\DTO\FileUploadRejectedSignalData;
use Demo\Chat\Core\Router\DTO\ModerationRequestSignalData;
use Demo\Chat\Core\Router\DTO\ModerationStateUpdateSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Collection\Bots;
use Demo\Chat\Hilos;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\HilosException;
use Hilos\Utils\Logger;
use Random\RandomException;
use Throwable;
use Hilos\Core\Page\PageRouteParams;

/**
 * MainPage - Main chat page handler.
 *
 * Handles subscription, unsubscription, and actions for the main chat page.
 * Used only with {@see ChatAgent} (MAIN subscriptions and chat actions are routed to the chat worker).
 */
final class MainPage extends AbstractChatPage
{
    use UploadFileTrait;

    public const string PAGE = PageConstants::MAIN;

    /**
     * Returns the {@see ChatAgent} bound to this page (same object as {@see AbstractPage::$agent}).
     *
     * {@see AbstractPage::$agent} is typed as {@see PageAgentInterface}; the assert narrows it for
     * static analysis and for dev when `zend.assertions=1`.
     *
     * @return ChatAgent
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
     * @param PageRouteParams $params Route params from page subscription (unused for main page)
     * @throws HilosException On database, runtime, or truth source failure
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
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
     * @throws HilosException On database, runtime, or truth source failure
     * @throws RandomException From {@see UploadFileTrait::handleFileUploadInit} (file upload id generation)
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        try {
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
        } catch (Throwable $e) {
            $this->handleActionFailure($acceptKey, $action, $e);
        }
    }

    private function handleActionFailure(string $acceptKey, string $action, Throwable $e): void
    {
        Logger::logAgentError('MainPage', "Action {$action} failed: {$e->getMessage()}");

        if ($action === ChatSignalConstants::FILE_UPLOAD_INIT) {
            $this->getChatAgent()->sendToUser(
                ChatSignalConstants::FILE_UPLOAD_REJECTED,
                $acceptKey,
                new FileUploadRejectedSignalData('server_error', $e->getMessage()),
            );
        }
    }

    /**
     * Handle message action.
     *
     * @param string $acceptKey Accept key
     * @param MessageActionDTO $dto Message DTO
     * @throws HilosException On database, runtime, or truth source failure
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

        Hilos::$rt->userStates->actions->setTextModerationMessage($userId, $dto->content);
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::MODERATION_STATE_UPDATE,
            $acceptKey,
            new ModerationStateUpdateSignalData(moderationState: $dto->content),
        );

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
