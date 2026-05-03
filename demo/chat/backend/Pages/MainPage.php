<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Page\DTO\AttachmentDraftDeleteActionDTO;
use Demo\Chat\Core\Page\DTO\FileUploadInitActionDTO;
use Demo\Chat\Core\Page\DTO\MessageActionDTO;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Core\Router\DTO\ModerationRequestSignalData;
use Demo\Chat\Core\Router\DTO\OutboundModerationStateUpdateSignalData;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\DTO\PublishedAttachmentInput;
use Demo\Chat\Database\DTO\PublishedAttachmentInputs;
use Demo\Chat\Frontend\BotFrontendStateProjector;
use Demo\Chat\Frontend\UserFrontendStateProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Main\UploadFileTrait;
use Demo\Chat\Runtime\View\Collection\AttachmentDrafts;
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
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Fs\FsException;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Handles main chat subscriptions, message submit actions, upload signals, and outbound moderation results.
 *
 * @property ChatAgent $agent
 */
final class MainPage extends AbstractPage
{
    use UploadFileTrait;

    public const string PAGE = PageConstants::MAIN;

    /**
     * Sends the initial main chat snapshot and connection-local session state.
     *
     * @param string $acceptKey WebSocket accept key for the subscribing client
     * @param PageRouteParams $params Route params from page subscription (unused for main page)
     * @throws PageInternalErrorException When the runtime connection row is missing for the subscribe accept key
     * @throws HilosException On database, runtime, or truth source failure
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            throw new PageInternalErrorException('No RT connection for this subscribe acceptKey');
        }

        $this->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_MAIN,
            $acceptKey,
            $this->buildMainSubscriptionSignal($acceptKey),
        );
    }

    /**
     * Builds the main-page subscription payload from shared chat state and connection-local fields.
     *
     * @param string $acceptKey WebSocket accept key whose attachment drafts are included
     * @throws HilosException On database, runtime, or truth source failure
     */
    private function buildMainSubscriptionSignal(string $acceptKey): ChatEventSignalDTO
    {
        return new ChatEventSignalDTO(
            new EntitiesChangesDTO(
                full: [
                    DbChatContext::bots => Hilos::$db->bots->activeOnly,
                    DbChatContext::events => Hilos::$db->events,
                ],
            ),
            outboundModerationState: $this->buildOutboundModerationStatePayload(
                Hilos::$rt->connections[$acceptKey]->userId,
            ),
            attachmentDrafts: Hilos::$rt->attachmentDrafts->toFrontendListForAcceptKey($acceptKey),
            fileUploadProgress: Hilos::$rt->connections[$acceptKey]->fileProgressFilename === null
                ? null
                : [
                    'filename' => Hilos::$rt->connections[$acceptKey]->fileProgressFilename,
                    'uploadedBytes' => Hilos::$rt->connections[$acceptKey]->fileProgressUploadedBytes,
                    'totalBytes' => Hilos::$rt->connections[$acceptKey]->fileProgressTotalBytes,
                ],
            includeUserSessionFields: true,
            frontend: BotFrontendStateProjector::appendFullForBots(
                UserFrontendStateProjector::fullForUsers(Hilos::$rt->connections->relevantUsers),
                Hilos::$db->bots->activeOnly,
            ),
        );
    }

    /**
     * Routes main-page actions to message, upload init, and attachment draft handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Main-page action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws HilosException On database, runtime, truth source, or signal failure
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
     * Routes main-page agent signals to outbound moderation handlers.
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
        switch ($name) {
            case ChatSignalConstants::MODERATION_RESULT:
                $moderationResult = $data->data;
                if (!$moderationResult instanceof ModerationResultSignalData) {
                    throw new InvalidAgentSignalPayloadException(
                        $name,
                        ModerationResultSignalData::class,
                        $moderationResult,
                    );
                }
                $this->handleTextModerationResult($moderationResult);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Delegates binary upload frames to the main-page upload handler.
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
     * Routes scheduled main-page cleanup cron names.
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
     * Starts outbound moderation for a valid text or attachment-backed message submit.
     *
     * @param string $acceptKey WebSocket accept key for the submitting client
     * @param MessageActionDTO $dto Parsed message action payload
     * @throws EmptyValueException When message has no non-empty text and no attachments
     * @throws ItemNotFoundForUpdateException When the WebSocket session or user runtime state is missing
     * @throws ValidationException When the user is rate-limited, already moderating, or references invalid drafts
     * @throws HilosException On database, runtime, or truth source failure
     */
    private function handleMessage(string $acceptKey, MessageActionDTO $dto): void
    {
        if ($dto->content === '' && $dto->attachmentDraftIds === []) {
            throw new EmptyValueException('Message cannot be empty');
        }
        if (trim($dto->content) === '' && $dto->attachmentDraftIds === []) {
            throw new EmptyValueException('Message cannot be trim-empty');
        }
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        if (!isset(Hilos::$rt->userStates[Hilos::$rt->connections[$acceptKey]->userId])) {
            throw new ItemNotFoundForUpdateException('User runtime state not found');
        }
        if (
            Hilos::$rt->userStates[Hilos::$rt->connections[$acceptKey]->userId]->outboundModerationPhase
            === ChatUserState::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            throw new ValidationException('Another message is already being moderated');
        }
        if (
            microtime(true) - Hilos::$rt->userStates[Hilos::$rt->connections[$acceptKey]->userId]->lastOutboundSubmittedAt
            < ChatUserState::MESSAGE_RATE_LIMIT_SECONDS - ChatUserState::MESSAGE_RATE_LIMIT_TOLERANCE_SECONDS
        ) {
            throw new ValidationException('Message rate limit is active');
        }

        $this->deleteExpiredAttachmentDrafts();
        $drafts = Hilos::$rt->attachmentDrafts->forAcceptKey($acceptKey)->forDraftIds($dto->attachmentDraftIds);
        if (count($drafts) !== count($dto->attachmentDraftIds)) {
            $this->sendAttachmentDraftsUpdate($acceptKey);
            throw new ValidationException('Attachment draft is no longer available');
        }

        $requestId = RandomHelper::hex(16);
        Hilos::$rt->userStates[Hilos::$rt->connections[$acceptKey]->userId]->actions->recordOutboundSubmitted();
        Hilos::$rt->userStates[Hilos::$rt->connections[$acceptKey]->userId]->actions->startOutboundModeration(
            $requestId,
            $dto->content,
            $dto->attachmentDraftIds,
        );
        $this->sendOutboundModerationStateUpdate($acceptKey, Hilos::$rt->connections[$acceptKey]->userId);

        $this->agent->sendToAgent(
            ChatSignalConstants::MODERATE_REQUEST,
            new ModerationRequestSignalData(
                requestId: $requestId,
                acceptKey: $acceptKey,
                userId: Hilos::$rt->connections[$acceptKey]->userId,
                message: $dto->content,
                contentForModeration: $this->buildContentForModeration($dto->content, $drafts),
            ),
        );
    }

    /**
     * Deletes one uploaded attachment draft owned by this WebSocket connection.
     *
     * @param string $acceptKey WebSocket accept key for the requesting client
     * @param AttachmentDraftDeleteActionDTO $dto Parsed delete action payload
     * @throws EmptyValueException When draft id is empty
     * @throws ItemNotFoundForUpdateException When the WebSocket session or user runtime state is missing
     * @throws ValidationException When the current outbound submit is being moderated
     * @throws HilosException On runtime, filesystem, or signal failure
     */
    private function handleAttachmentDraftDelete(string $acceptKey, AttachmentDraftDeleteActionDTO $dto): void
    {
        if ($dto->draftId === '') {
            throw new EmptyValueException('Attachment draft id cannot be empty');
        }
        if (trim($dto->draftId) === '') {
            throw new EmptyValueException('Attachment draft id cannot be trim-empty');
        }
        if (!isset(Hilos::$rt->connections[$acceptKey])) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        if (!isset(Hilos::$rt->userStates[Hilos::$rt->connections[$acceptKey]->userId])) {
            throw new ItemNotFoundForUpdateException('User runtime state not found');
        }
        if (
            Hilos::$rt->userStates[Hilos::$rt->connections[$acceptKey]->userId]->outboundModerationPhase
            === ChatUserState::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            throw new ValidationException('Cannot delete attachment while message is being moderated');
        }

        if (!isset(Hilos::$rt->attachmentDrafts->forAcceptKey($acceptKey)[$dto->draftId])) {
            $this->sendAttachmentDraftsUpdate($acceptKey);

            return;
        }

        Hilos::$rt->attachmentDrafts[$dto->draftId]->actions->delete(deleteFiles: true);
        $this->sendAttachmentDraftsUpdate($acceptKey);

        if (
            Hilos::$rt->userStates[Hilos::$rt->connections[$acceptKey]->userId]->outboundModerationPhase
            !== ChatUserState::OUTBOUND_MODERATION_PHASE_NONE
        ) {
            $this->sendOutboundModerationStateUpdate($acceptKey, Hilos::$rt->connections[$acceptKey]->userId);
        }
    }

    /**
     * Applies outbound moderation: publish approved text plus attachments or expose a retryable failure state.
     *
     * Stale connection or request results never publish a message.
     *
     * @param ModerationResultSignalData $result Uploader connection key, request id, allow flag, message body, reason
     * @throws HilosException On database, runtime, or signal failure
     */
    private function handleTextModerationResult(ModerationResultSignalData $result): void
    {
        if (
            !isset(Hilos::$rt->connections[$result->acceptKey])
            || Hilos::$rt->connections[$result->acceptKey]->userId !== $result->userId
        ) {
            Hilos::$rt->userStates[$result->userId]?->actions->clearOutboundModeration($result->requestId);
            $this->logAgentInfo(
                "Moderation result ignored for stale connection (acceptKey={$result->acceptKey}; userId={$result->userId})",
            );
            return;
        }

        if (!isset(Hilos::$rt->userStates[$result->userId])) {
            $this->logAgentInfo(
                "Moderation result ignored for missing runtime user state (acceptKey={$result->acceptKey}; userId={$result->userId}; requestId={$result->requestId})",
            );
            return;
        }

        if (!$result->allow) {
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            $phase = in_array($reason, ['service_unavailable', 'unknown'], true)
                ? ChatUserState::OUTBOUND_MODERATION_PHASE_UNAVAILABLE
                : ChatUserState::OUTBOUND_MODERATION_PHASE_REJECTED;
            if (
                !Hilos::$rt->userStates[$result->userId]->actions->failOutboundModeration(
                    $result->requestId,
                    $phase,
                    $reason,
                )
            ) {
                $this->logAgentInfo(
                    "Moderation result ignored for stale request (acceptKey={$result->acceptKey}; userId={$result->userId}; requestId={$result->requestId})",
                );
                return;
            }
            $this->sendOutboundModerationStateUpdate($result->acceptKey, $result->userId);
            $this->logAgentError("Message blocked by moderation (userId={$result->userId}; reason={$reason})");
            return;
        }

        $draftIds = Hilos::$rt->userStates[$result->userId]->getOutboundModerationAttachmentDraftIds();
        $drafts = Hilos::$rt->attachmentDrafts->forAcceptKey($result->acceptKey)->forDraftIds($draftIds);
        if (count($drafts) !== count($draftIds)) {
            Hilos::$rt->userStates[$result->userId]->actions->failOutboundModeration(
                $result->requestId,
                ChatUserState::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
                'attachment_missing',
            );
            $this->sendOutboundModerationStateUpdate($result->acceptKey, $result->userId);
            $this->sendAttachmentDraftsUpdate($result->acceptKey);
            return;
        }

        $attachments = [];
        foreach ($drafts as $draft) {
            $quarantineFile = Hilos::$fs->quarantine[$draft->quarantineBasename];
            if (!$quarantineFile->exists()) {
                Hilos::$rt->userStates[$result->userId]->actions->failOutboundModeration(
                    $result->requestId,
                    ChatUserState::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
                    'attachment_missing',
                );
                Hilos::$rt->attachmentDrafts[$draft->draftId]->actions->delete(deleteFiles: false);
                $this->sendOutboundModerationStateUpdate($result->acceptKey, $result->userId);
                $this->sendAttachmentDraftsUpdate($result->acceptKey);
                return;
            }
            try {
                $quarantineFile->move('published');
            } catch (FsException $e) {
                $this->logAgentError("Failed to publish attachment draft {$draft->draftId}: {$e->getMessage()}");
                Hilos::$rt->userStates[$result->userId]->actions->failOutboundModeration(
                    $result->requestId,
                    ChatUserState::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
                    'attachment_publish_failed',
                );
                $this->sendOutboundModerationStateUpdate($result->acceptKey, $result->userId);
                return;
            }
            $attachments[] = new PublishedAttachmentInput(
                filename: $draft->originalFilename,
                mimeType: $draft->mimeType,
                storedName: $draft->quarantineBasename,
            );
        }

        if (!Hilos::$rt->userStates[$result->userId]->actions->clearOutboundModeration($result->requestId)) {
            $this->logAgentInfo(
                "Moderation approval ignored for stale request (acceptKey={$result->acceptKey}; userId={$result->userId}; requestId={$result->requestId})",
            );
            return;
        }

        Hilos::$rt->attachmentDrafts->actions->deleteByIds($draftIds, deleteFiles: false);
        $this->sendOutboundModerationStateUpdate($result->acceptKey, $result->userId);
        $this->sendAttachmentDraftsUpdate($result->acceptKey);
        Hilos::$db->events->actions->addMessage(
            $result->message,
            userId: $result->userId,
            attachments: new PublishedAttachmentInputs(...$attachments),
        );
    }

    /**
     * Builds the current user outbound moderation payload for the frontend.
     *
     * @param int $userId Database user id
     * @return ?array<string, mixed> Moderation UI payload or null
     */
    private function buildOutboundModerationStatePayload(int $userId): ?array
    {
        $state = Hilos::$rt->userStates[$userId] ?? null;
        if (
            $state === null
            || $state->outboundModerationPhase === ChatUserState::OUTBOUND_MODERATION_PHASE_NONE
        ) {
            return null;
        }

        $attachments = [];
        foreach (
            Hilos::$rt->attachmentDrafts->forDraftIds($state->getOutboundModerationAttachmentDraftIds()) as $draft
        ) {
            $attachments[] = AttachmentDrafts::toFrontendRow($draft);
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
     * Sends current outbound moderation state to a connection.
     *
     * @param string $acceptKey WebSocket accept key for the target client
     * @param int $userId Database user id whose moderation state is sent
     * @throws HilosException On runtime or signal failure
     */
    private function sendOutboundModerationStateUpdate(string $acceptKey, int $userId): void
    {
        $this->sendToUser(
            ChatSignalConstants::OUTBOUND_MODERATION_STATE_UPDATE,
            $acceptKey,
            new OutboundModerationStateUpdateSignalData($this->buildOutboundModerationStatePayload($userId)),
        );
    }

    /**
     * Builds moderation prompt content from message text and attachment metadata.
     *
     * @param AttachmentDrafts $drafts Attachment drafts
     */
    private function buildContentForModeration(string $message, AttachmentDrafts $drafts): string
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
