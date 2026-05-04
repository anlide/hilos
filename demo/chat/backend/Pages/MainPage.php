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
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Core\Router\DTO\ModerationRequestSignalData;
use Demo\Chat\Database\DTO\PublishedAttachmentInput;
use Demo\Chat\Database\DTO\PublishedAttachmentInputs;
use Demo\Chat\Frontend\MainPageSubscriptionProjector;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Main\UploadFileTrait;
use Demo\Chat\Runtime\View\Item\ChatUserState;
use Demo\Chat\Runtime\View\Item\Connection;
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
        if (Hilos::$rt->selfConnection === null) {
            throw new PageInternalErrorException('No RT connection for this subscribe acceptKey');
        }

        $this->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_MAIN,
            $acceptKey,
            MainPageSubscriptionProjector::forConnection(Hilos::$rt->selfConnection),
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
     * @throws ValidationException When the user is rate-limited or already moderating
     * @throws HilosException On database, runtime, or truth source failure
     */
    private function handleMessage(string $acceptKey, MessageActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        if (Hilos::$rt->selfConnection->userState === null) {
            throw new ItemNotFoundForUpdateException('User runtime state not found');
        }
        if (
            Hilos::$rt->selfConnection->outboundModerationPhase
            === Connection::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            throw new ValidationException('Another message is already being moderated');
        }
        if (
            microtime(true) - Hilos::$rt->selfConnection->userState->lastOutboundSubmittedAt
            < ChatUserState::MESSAGE_RATE_LIMIT_SECONDS - ChatUserState::MESSAGE_RATE_LIMIT_TOLERANCE_SECONDS
        ) {
            throw new ValidationException('Message rate limit is active');
        }

        $this->deleteExpiredAttachmentDrafts();
        if ($dto->content === '' && count(Hilos::$rt->selfConnection->attachmentDrafts) === 0) {
            throw new EmptyValueException('Message cannot be empty');
        }
        if (trim($dto->content) === '' && count(Hilos::$rt->selfConnection->attachmentDrafts) === 0) {
            throw new EmptyValueException('Message cannot be trim-empty');
        }

        $requestId = RandomHelper::hex(16);
        Hilos::$rt->selfConnection->userState->actions->recordOutboundSubmission();
        Hilos::$rt->selfConnection->actions->startOutboundModeration(
            $requestId,
            $dto->content,
        );

        $this->agent->sendToAgent(
            ChatSignalConstants::MODERATE_REQUEST,
            new ModerationRequestSignalData(
                requestId: $requestId,
                acceptKey: $acceptKey,
                userId: Hilos::$rt->selfConnection->userId,
                message: $dto->content,
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
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        if (Hilos::$rt->selfConnection->userState === null) {
            throw new ItemNotFoundForUpdateException('User runtime state not found');
        }
        if (
            Hilos::$rt->selfConnection->outboundModerationPhase
            === Connection::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            throw new ValidationException('Cannot delete attachment while message is being moderated');
        }

        if (!isset(Hilos::$rt->selfConnection->attachmentDrafts[$dto->draftId])) {
            // TODO: Reconcile stale draft delete requests through a projection-backed no-op action.

            return;
        }

        Hilos::$rt->attachmentDrafts[$dto->draftId]->actions->delete(deleteFiles: true);
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
            $this->logAgentInfo(
                "Moderation result ignored for stale connection (acceptKey={$result->acceptKey}; userId={$result->userId})",
            );
            return;
        }

        if (Hilos::$rt->connections[$result->acceptKey]->outboundModerationRequestId !== $result->requestId) {
            $this->logAgentInfo(
                "Moderation result ignored for stale request (acceptKey={$result->acceptKey}; userId={$result->userId}; requestId={$result->requestId})",
            );
            return;
        }

        if (!$result->allow) {
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            $phase = in_array($reason, ['service_unavailable', 'unknown'], true)
                ? Connection::OUTBOUND_MODERATION_PHASE_UNAVAILABLE
                : Connection::OUTBOUND_MODERATION_PHASE_REJECTED;
            if (
                !Hilos::$rt->connections[$result->acceptKey]->actions->failOutboundModeration(
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
            $this->logAgentError("Message blocked by moderation (userId={$result->userId}; reason={$reason})");
            return;
        }

        $drafts = [];
        $draftIds = [];
        foreach (Hilos::$rt->connections[$result->acceptKey]->attachmentDrafts as $draft) {
            $drafts[] = $draft;
            $draftIds[] = $draft->draftId;
        }
        if ($result->message === '' && $drafts === []) {
            Hilos::$rt->connections[$result->acceptKey]->actions->failOutboundModeration(
                $result->requestId,
                Connection::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
                'attachment_missing',
            );
            return;
        }

        $attachments = [];
        foreach ($drafts as $draft) {
            $quarantineFile = Hilos::$fs->quarantine[$draft->quarantineBasename];
            if (!$quarantineFile->exists()) {
                Hilos::$rt->connections[$result->acceptKey]->actions->failOutboundModeration(
                    $result->requestId,
                    Connection::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
                    'attachment_missing',
                );
                Hilos::$rt->attachmentDrafts[$draft->draftId]->actions->delete(deleteFiles: false);
                return;
            }
            try {
                $quarantineFile->move('published');
            } catch (FsException $e) {
                $this->logAgentError("Failed to publish attachment draft {$draft->draftId}: {$e->getMessage()}");
                Hilos::$rt->connections[$result->acceptKey]->actions->failOutboundModeration(
                    $result->requestId,
                    Connection::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
                    'attachment_publish_failed',
                );
                return;
            }
            $attachments[] = new PublishedAttachmentInput(
                filename: $draft->originalFilename,
                mimeType: $draft->mimeType,
                storedName: $draft->quarantineBasename,
            );
        }

        if (!Hilos::$rt->connections[$result->acceptKey]->actions->clearOutboundModeration($result->requestId)) {
            $this->logAgentInfo(
                "Moderation approval ignored for stale request (acceptKey={$result->acceptKey}; userId={$result->userId}; requestId={$result->requestId})",
            );
            return;
        }

        Hilos::$rt->attachmentDrafts->actions->deleteByIds($draftIds, deleteFiles: false);
        Hilos::$db->events->actions->addMessage(
            $result->message,
            userId: $result->userId,
            attachments: new PublishedAttachmentInputs(...$attachments),
        );
    }
}
