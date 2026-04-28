<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Page\DTO\FileModerationDismissActionDTO;
use Demo\Chat\Core\Page\DTO\FileUploadInitActionDTO;
use Demo\Chat\Core\Page\DTO\MessageActionDTO;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Core\Router\DTO\ModerationFileResultSignalData;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Core\Router\DTO\ModerationRequestSignalData;
use Demo\Chat\Core\Router\DTO\ModerationStateUpdateSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Collection\Bots;
use Demo\Chat\Database\View\Collection\Events;
use Demo\Chat\Frontend\UserFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Main\UploadFileTrait;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Random\RandomException;

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
     * @return ChatAgent Chat worker bound to the main chat page
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
     * @throws PageInternalErrorException If `acceptKey` is missing from `Hilos::$rt->connections` (invariant; should not occur in normal operation)
     * @throws HilosException On database, runtime, or truth source failure
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            throw new PageInternalErrorException('No RT connection for this subscribe acceptKey');
        }
        $conn = Hilos::$rt->connections[$acceptKey];
        $pending = Hilos::$rt->userStates[$conn->userId]?->moderationMessage;
        $fileModPhase = $conn->fileModPhase;
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_MAIN,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(
                    full: [
                        DbChatContext::bots => Bots::fromActiveOnly(),
                        DbChatContext::events => Hilos::$db->events,
                    ],
                ),
                [],
                moderationState: $pending !== '' ? $pending : null,
                fileModerationState: $fileModPhase === null ? null : [
                    'phase' => $fileModPhase,
                    'filename' => $conn->fileModFilename,
                    'uploadedBytes' => $conn->fileModUploadedBytes,
                    'totalBytes' => $conn->fileModTotalBytes,
                    'reason' => $conn->fileModReason !== '' ? $conn->fileModReason : null,
                    'updatedAt' => $conn->fileModUpdatedAt,
                ],
                fileUploadProgress: $conn->fileProgressFilename === null ? null : [
                    'filename' => $conn->fileProgressFilename,
                    'uploadedBytes' => $conn->fileProgressUploadedBytes,
                    'totalBytes' => $conn->fileProgressTotalBytes,
                ],
                includeUserSessionFields: true,
                frontend: UserFrontendStateProjector::fullForUsers(Hilos::$rt->connections->relevantUsers),
            ),
        );
    }

    /**
     * Handle page-specific action logic.
     *
     * @param string $acceptKey Accept key
     * @param string $action Action name
     * @param ActionPayloadDTO $dto Action payload DTO
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws HilosException On database, runtime, truth source, or signal failure
     * @throws RandomException When file upload id generation fails
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
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Route main-page agent signals to text and file moderation handlers.
     *
     * @param AgentSignalData $data Wrapped moderation result payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Moderation result signal name
     * @throws HilosException On database, runtime, truth source, or signal failure
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        $payload = $data->data;

        switch ($name) {
            case ChatSignalConstants::MODERATION_RESULT:
                if ($payload instanceof ModerationResultSignalData) {
                    $this->handleTextModerationResult($payload);
                } else {
                    $this->logAgentError("Invalid payload type for {$name}: " . get_debug_type($payload));
                }

                return;

            case ChatSignalConstants::MODERATION_FILE_RESULT:
                if ($payload instanceof ModerationFileResultSignalData) {
                    $this->handleModerationFileResult($payload);
                } else {
                    $this->logAgentError("Invalid payload type for {$name}: " . get_debug_type($payload));
                }

                return;

            default:
                $this->logAgentError("Invalid payload type for {$name}: " . get_debug_type($payload));
        }
    }

    /**
     * Route binary upload frames to the main-page upload handler.
     *
     * @param WebSocketFrameBinarySignalDTO $data Frame payload and connection id
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Framework signal name (unused)
     * @throws HilosException On runtime or signal failure
     */
    public function onSignalFrameBinary(WebSocketFrameBinarySignalDTO $data, string $source, string $name): void
    {
        $this->handleFileUploadBinaryFrame($data);
    }

    /**
     * Run scheduled main-page file cleanup for the chat cleanup cron.
     *
     * @param SignalDataInterface $data Cron payload (unused)
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Task name
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        if ($name !== ChatCronConstants::CLEANUP_HISTORY) {
            return;
        }

        $this->deleteAllAttachmentFilesFromDisk();
    }

    /**
     * Handle message action.
     *
     * @param string $acceptKey Accept key
     * @param MessageActionDTO $dto Message DTO
     * @throws EmptyValueException When message text is empty
     * @throws ItemNotFoundForUpdateException When the WebSocket session is missing
     * @throws ValidationException When the user is still rate-limited
     * @throws HilosException On database, runtime, or truth source failure
     */
    private function handleMessage(string $acceptKey, MessageActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            throw new EmptyValueException('Message cannot be empty');
        }

        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        $userId = Hilos::$rt->connections[$acceptKey]->userId;
        if (!Hilos::$rt->userStates->actions->canSendMessage($userId)) {
            throw new ValidationException('Message rate limit is active');
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

    /**
     * Apply text message moderation: clear pending state and publish approved messages.
     *
     * Stale connection results clear the user's pending moderation state without publishing a message.
     *
     * @param ModerationResultSignalData $result Uploader connection key, user id, allow flag, message body, reason
     * @throws HilosException On database, runtime, or signal failure
     */
    private function handleTextModerationResult(ModerationResultSignalData $result): void
    {
        $acceptKey = $result->acceptKey;
        $userId = $result->userId;

        $connection = Hilos::$rt->connections[$acceptKey] ?? null;
        if ($connection === null || $connection->userId !== $userId) {
            if (isset(Hilos::$rt->userStates[$userId])) {
                Hilos::$rt->userStates->actions->clearTextModerationMessage($userId);
            }
            $this->logAgentInfo(
                "Moderation result ignored for stale connection (acceptKey={$acceptKey}; userId={$userId})",
            );
            return;
        }

        Hilos::$rt->userStates->actions->clearTextModerationMessage($userId);
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::MODERATION_STATE_UPDATE,
            $acceptKey,
            new ModerationStateUpdateSignalData(moderationState: null),
        );

        if (!$result->allow) {
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            $this->logAgentError("Message blocked by moderation (userId={$userId}; reason={$reason})");
            return;
        }

        Hilos::$rt->userStates->actions->recordMessageSent($userId);
        $event = Hilos::$db->events->actions->addMessage($result->message, userId: $userId);
        $this->getChatAgent()->sendToAllUsers(
            ChatSignalConstants::NEW_EVENT,
            new ChatEventSignalDTO(new EntitiesChangesDTO(full: [DbChatContext::events => Events::fromSingleItem($event)])),
        );
    }
}
