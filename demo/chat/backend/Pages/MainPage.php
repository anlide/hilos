<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\AbstractChatPage;
use Demo\Chat\Core\Page\DTO\AttachmentDraftDeleteActionDTO;
use Demo\Chat\Core\Page\DTO\FileUploadInitActionDTO;
use Demo\Chat\Core\Page\DTO\MessageActionDTO;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Core\Router\DTO\ModerationRequestSignalData;
use Demo\Chat\Core\Router\DTO\OutboundModerationStateUpdateSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Collection\Bots;
use Demo\Chat\Frontend\BotFrontendStateProjector;
use Demo\Chat\Frontend\UserFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Main\UploadFileTrait;
use Demo\Chat\Runtime\View\Collection\AttachmentDrafts;
use Demo\Chat\Runtime\View\Item\AttachmentDraft;
use Demo\Chat\Runtime\View\Item\ChatUserState;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
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
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Fs\FsException;
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
        $activeBots = Bots::fromActiveOnly();
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_MAIN,
            $acceptKey,
            new ChatEventSignalDTO(
                new EntitiesChangesDTO(
                    full: [
                        DbChatContext::bots => $activeBots,
                        DbChatContext::events => Hilos::$db->events,
                    ],
                ),
                [],
                outboundModerationState: $this->buildOutboundModerationStatePayload($conn->userId),
                attachmentDrafts: Hilos::$rt->attachmentDrafts->toFrontendListForAcceptKey($acceptKey),
                fileUploadProgress: $conn->fileProgressFilename === null ? null : [
                    'filename' => $conn->fileProgressFilename,
                    'uploadedBytes' => $conn->fileProgressUploadedBytes,
                    'totalBytes' => $conn->fileProgressTotalBytes,
                ],
                includeUserSessionFields: true,
                frontend: BotFrontendStateProjector::appendFullForBots(
                    UserFrontendStateProjector::fullForUsers(Hilos::$rt->connections->relevantUsers),
                    $activeBots,
                ),
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
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws HilosException On database, runtime, truth source, or signal failure
     * @throws RandomException When file upload id generation fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): void
    {
        switch ($action) {
            case ChatSignalConstants::MESSAGE:
                if (!$dto instanceof MessageActionDTO) {
                    throw new InvalidActionPayloadException($action, MessageActionDTO::class, $dto);
                }
                $this->handleMessage($acceptKey, $dto);

                break;

            case ChatSignalConstants::FILE_UPLOAD_INIT:
                if (!$dto instanceof FileUploadInitActionDTO) {
                    throw new InvalidActionPayloadException($action, FileUploadInitActionDTO::class, $dto);
                }
                $this->handleFileUploadInit($acceptKey, $dto);

                break;

            case ChatSignalConstants::ATTACHMENT_DRAFT_DELETE:
                if (!$dto instanceof AttachmentDraftDeleteActionDTO) {
                    throw new InvalidActionPayloadException($action, AttachmentDraftDeleteActionDTO::class, $dto);
                }
                $this->handleAttachmentDraftDelete($acceptKey, $dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }
    }

    /**
     * Route main-page agent signals to outbound moderation handlers.
     *
     * @param AgentSignalData $data Wrapped moderation result payload
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Moderation result signal name
     * @throws AgentUnknownSignalException When signal name is not supported by this page
     * @throws InvalidAgentSignalPayloadException When signal payload does not match the signal name
     * @throws HilosException On database, runtime, truth source, or signal failure
     */
    public function onSignalAgent(AgentSignalData $data, string $source, string $name): void
    {
        $payload = $data->data;

        switch ($name) {
            case ChatSignalConstants::MODERATION_RESULT:
                if (!$payload instanceof ModerationResultSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, ModerationResultSignalData::class, $payload);
                }
                $this->handleTextModerationResult($payload);

                return;

            default:
                throw new AgentUnknownSignalException($name);
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
     * Route scheduled main-page cleanup cron names.
     *
     * @param SignalDataInterface $data Cron payload (unused)
     * @param string $source Framework signal source identifier (unused)
     * @param string $name Task name
     * @throws AgentUnknownSignalException When cron name is not supported by this page
     */
    public function onSignalCron(SignalDataInterface $data, string $source, string $name): void
    {
        switch ($name) {
            case ChatCronConstants::CLEANUP_HISTORY:
                $this->deleteAllAttachmentFilesFromDisk();

                return;

            case ChatCronConstants::CLEANUP_ATTACHMENT_DRAFTS:
                $this->deleteExpiredAttachmentDrafts();

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Handle message action.
     *
     * @param string $acceptKey Accept key
     * @param MessageActionDTO $dto Message DTO
     * @throws EmptyValueException When message has no text and no attachments
     * @throws ItemNotFoundForUpdateException When the WebSocket session or user runtime state is missing
     * @throws ValidationException When the user is rate-limited, already moderating, or references invalid drafts
     * @throws HilosException On database, runtime, or truth source failure
     * @throws RandomException When moderation request id generation fails
     */
    private function handleMessage(string $acceptKey, MessageActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            throw new EmptyValueException('Message cannot be empty');
        }

        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        $connection = Hilos::$rt->connections[$acceptKey];
        $userId = $connection->userId;
        $userState = $this->getUserState($userId);
        if ($userState->hasActiveOutboundModeration()) {
            throw new ValidationException('Another message is already being moderated');
        }
        if (!$userState->canStartOutboundSubmission()) {
            throw new ValidationException('Message rate limit is active');
        }

        $this->deleteExpiredAttachmentDrafts();
        $drafts = Hilos::$rt->attachmentDrafts->findAllByIdsForAcceptKey($acceptKey, $dto->attachmentDraftIds);
        if (count($drafts) !== count($dto->attachmentDraftIds)) {
            $this->sendAttachmentDraftsUpdate($acceptKey);
            throw new ValidationException('Attachment draft is no longer available');
        }

        $requestId = bin2hex(random_bytes(16));
        Hilos::$rt->userStates->actions->recordOutboundSubmitted($userId);
        Hilos::$rt->userStates->actions->startOutboundModeration(
            $userId,
            $requestId,
            $dto->content,
            $dto->attachmentDraftIds,
        );
        $this->sendOutboundModerationStateUpdate($acceptKey, $userId);

        $this->getChatAgent()->sendToAgent(
            ChatSignalConstants::MODERATE_REQUEST,
            new ModerationRequestSignalData(
                requestId: $requestId,
                acceptKey: $acceptKey,
                userId: $userId,
                message: $dto->content,
                contentForModeration: $this->buildContentForModeration($dto->content, $drafts),
            ),
        );
    }

    /**
     * Delete one uploaded attachment draft owned by this WebSocket connection.
     *
     * @param string $acceptKey Accept key
     * @param AttachmentDraftDeleteActionDTO $dto Delete action payload
     * @throws EmptyValueException When draft id is empty
     * @throws ItemNotFoundForUpdateException When the WebSocket session or user runtime state is missing
     * @throws ValidationException When the current outbound submit is being moderated
     * @throws HilosException On runtime, filesystem, or signal failure
     */
    private function handleAttachmentDraftDelete(string $acceptKey, AttachmentDraftDeleteActionDTO $dto): void
    {
        if (!$dto->isValid()) {
            throw new EmptyValueException('Attachment draft id cannot be empty');
        }

        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }

        $userId = Hilos::$rt->connections[$acceptKey]->userId;
        if ($this->getUserState($userId)->hasActiveOutboundModeration()) {
            throw new ValidationException('Cannot delete attachment while message is being moderated');
        }

        $deleted = Hilos::$rt->attachmentDrafts->actions->deleteForAcceptKeyById(
            $acceptKey,
            $dto->draftId,
            deleteFiles: true,
        );
        $this->sendAttachmentDraftsUpdate($acceptKey);

        if ($deleted && (Hilos::$rt->userStates[$userId]?->outboundModerationPhase ?? '') !== '') {
            $this->sendOutboundModerationStateUpdate($acceptKey, $userId);
        }
    }

    /**
     * Apply outbound moderation: publish approved text plus attachments or expose a retryable failure state.
     *
     * Stale connection or request results never publish a message.
     *
     * @param ModerationResultSignalData $result Uploader connection key, request id, allow flag, message body, reason
     * @throws HilosException On database, runtime, or signal failure
     */
    private function handleTextModerationResult(ModerationResultSignalData $result): void
    {
        $acceptKey = $result->acceptKey;
        $userId = $result->userId;

        $connection = Hilos::$rt->connections[$acceptKey] ?? null;
        if ($connection === null || $connection->userId !== $userId) {
            if (isset(Hilos::$rt->userStates[$userId])) {
                Hilos::$rt->userStates->actions->clearOutboundModeration($userId, $result->requestId);
            }
            $this->logAgentInfo(
                "Moderation result ignored for stale connection (acceptKey={$acceptKey}; userId={$userId})",
            );
            return;
        }

        $userState = Hilos::$rt->userStates[$userId] ?? null;
        if ($userState === null) {
            $this->logAgentInfo(
                "Moderation result ignored for missing runtime user state (acceptKey={$acceptKey}; userId={$userId}; requestId={$result->requestId})",
            );
            return;
        }

        if (!$result->allow) {
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            $phase = in_array($reason, ['service_unavailable', 'unknown'], true) ? 'unavailable' : 'rejected';
            if (!Hilos::$rt->userStates->actions->failOutboundModeration($userId, $result->requestId, $phase, $reason)) {
                $this->logAgentInfo(
                    "Moderation result ignored for stale request (acceptKey={$acceptKey}; userId={$userId}; requestId={$result->requestId})",
                );
                return;
            }
            $this->sendOutboundModerationStateUpdate($acceptKey, $userId);
            $this->logAgentError("Message blocked by moderation (userId={$userId}; reason={$reason})");
            return;
        }

        $draftIds = $userState->getOutboundModerationAttachmentDraftIds();
        $drafts = Hilos::$rt->attachmentDrafts->findAllByIdsForAcceptKey($acceptKey, $draftIds);
        if (count($drafts) !== count($draftIds)) {
            Hilos::$rt->userStates->actions->failOutboundModeration(
                $userId,
                $result->requestId,
                'unavailable',
                'attachment_missing',
            );
            $this->sendOutboundModerationStateUpdate($acceptKey, $userId);
            $this->sendAttachmentDraftsUpdate($acceptKey);
            return;
        }

        $attachments = [];
        foreach ($drafts as $draft) {
            $quarantineFile = Hilos::$fs->quarantine[$draft->quarantineBasename];
            if (!$quarantineFile->exists()) {
                Hilos::$rt->userStates->actions->failOutboundModeration(
                    $userId,
                    $result->requestId,
                    'unavailable',
                    'attachment_missing',
                );
                Hilos::$rt->attachmentDrafts->actions->deleteByIds([$draft->draftId], deleteFiles: false);
                $this->sendOutboundModerationStateUpdate($acceptKey, $userId);
                $this->sendAttachmentDraftsUpdate($acceptKey);
                return;
            }
            try {
                $quarantineFile->move('published');
            } catch (FsException $e) {
                $this->logAgentError("Failed to publish attachment draft {$draft->draftId}: {$e->getMessage()}");
                Hilos::$rt->userStates->actions->failOutboundModeration(
                    $userId,
                    $result->requestId,
                    'unavailable',
                    'attachment_publish_failed',
                );
                $this->sendOutboundModerationStateUpdate($acceptKey, $userId);
                return;
            }
            $attachments[] = [
                'originalFilename' => $draft->originalFilename,
                'mimeType' => $draft->mimeType,
                'size' => $draft->size,
                'downloadToken' => pathinfo($draft->quarantineBasename, PATHINFO_FILENAME),
                'storedName' => $draft->quarantineBasename,
            ];
        }

        if (!Hilos::$rt->userStates->actions->clearOutboundModeration($userId, $result->requestId)) {
            $this->logAgentInfo(
                "Moderation approval ignored for stale request (acceptKey={$acceptKey}; userId={$userId}; requestId={$result->requestId})",
            );
            return;
        }

        Hilos::$rt->attachmentDrafts->actions->deleteByIds($draftIds, deleteFiles: false);
        $this->sendOutboundModerationStateUpdate($acceptKey, $userId);
        $this->sendAttachmentDraftsUpdate($acceptKey);
        Hilos::$db->events->actions->addMessage($result->message, userId: $userId, attachments: $attachments);
    }

    /**
     * Build the frontend state for the current user outbound moderation.
     *
     * @return ?array<string, mixed> Moderation UI payload or null
     */
    private function buildOutboundModerationStatePayload(int $userId): ?array
    {
        $state = Hilos::$rt->userStates[$userId] ?? null;
        if ($state === null || $state->outboundModerationPhase === '') {
            return null;
        }

        $draftIds = $state->getOutboundModerationAttachmentDraftIds();
        $attachments = [];
        foreach ($draftIds as $draftId) {
            $draft = Hilos::$rt->attachmentDrafts[$draftId] ?? null;
            if ($draft !== null) {
                $attachments[] = AttachmentDrafts::toFrontendRow($draft);
            }
        }

        return [
            'requestId' => $state->outboundModerationRequestId,
            'phase' => $state->outboundModerationPhase,
            'text' => $state->outboundModerationMessage,
            'attachments' => $attachments,
            'reason' => $state->outboundModerationReason !== '' ? $state->outboundModerationReason : null,
            'updatedAt' => $state->outboundModerationUpdatedAt,
        ];
    }

    /**
     * Send current outbound moderation state to a connection.
     */
    private function sendOutboundModerationStateUpdate(string $acceptKey, int $userId): void
    {
        $this->getChatAgent()->sendToUser(
            ChatSignalConstants::OUTBOUND_MODERATION_STATE_UPDATE,
            $acceptKey,
            new OutboundModerationStateUpdateSignalData($this->buildOutboundModerationStatePayload($userId)),
        );
    }

    /**
     * Load required per-user runtime state.
     *
     * @param int $userId Database user id
     * @throws ItemNotFoundForUpdateException When user runtime state is missing
     */
    private function getUserState(int $userId): ChatUserState
    {
        return Hilos::$rt->userStates[$userId]
            ?? throw new ItemNotFoundForUpdateException('User runtime state not found');
    }

    /**
     * Build the moderation prompt content from message text and attachment metadata.
     *
     * @param list<AttachmentDraft> $drafts Attachment drafts
     */
    private function buildContentForModeration(string $message, array $drafts): string
    {
        $parts = [];
        if ($message !== '') {
            $parts[] = "Message:\n{$message}";
        }
        foreach ($drafts as $draft) {
            $parts[] = sprintf(
                'Attachment: name=%s, mime=%s, size=%d bytes.',
                $draft->originalFilename,
                $draft->mimeType,
                $draft->size,
            );
        }

        return implode("\n\n", $parts);
    }
}
