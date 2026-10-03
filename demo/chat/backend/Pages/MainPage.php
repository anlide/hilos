<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatNotificationType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Files\ChatAttachmentUploadTarget;
use Demo\Chat\Pages\DTO\Main\MessageActionDTO;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Item\ChatUserState;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalSource;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\Upload\UploadPhase;
use Hilos\HilosException;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Runtime\State\Item\HilosUpload;

/**
 * Handles main chat subscriptions, message submit actions, outbound moderation results, and the
 * publication of an approved message's attachments.
 *
 * The files themselves travel outside this page: the browser sends each one to the framework
 * uploads agent under the chat's upload target, and a message names the complete ones by their
 * client ids. On approval the page asks the files registry to publish them and writes the
 * message once the answer comes (HIL-144).
 *
 * @property ChatAgent $agent
 */
final class MainPage extends AbstractPage
{
    /** @var list<string> The event it opens, the attachments its feed shows, and the registry rows they link */
    public const array READS_DB = [ChatDbContext::events, ChatDbContext::eventAttachments, HilosDbContext::files];

    /** @var list<string> The uploads a submitted message names, judged ready before moderation starts */
    public const array READS_RT = [HilosUpload::RT_COLLECTION];

    public const string PAGE = PageConstants::MAIN;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    public const array ACTIONS = [
        ChatSignalConstants::MESSAGE => MessageActionDTO::class,
    ];

    // Sending a message requires a signed-in session: an anonymous visitor reads
    // the chat but is denied MESSAGE with a typed 401 (the frontend pre-disables
    // the composer and opens sign-in). The ways in are not here any more - they are
    // the users library's commands (HIL-622), and so is the guard over them.
    // Sending a file to attach is the framework uploads agent's action: the chat's
    // upload target requires sign-in itself (ChatAttachmentUploadTarget).
    public const array AUTH_ACTIONS = [
        ChatSignalConstants::MESSAGE,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            ChatSignalConstants::MODERATION_RESULT => ModerationResultSignalData::class,
            ChatSignalConstants::ATTACHMENTS_PUBLISHED => FilesPublishedSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_MAIN,
    ];

    /**
     * Routes the main-page message action to its handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Main-page action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws EmptyValueException When the message carries neither text nor a file
     * @throws ItemNotFoundForUpdateException When the WebSocket session or user runtime state is missing
     * @throws ValidationException When the message is refused - rate limit, moderation running, a file not ready or named twice
     * @throws HilosException When the uploads or runtime state cannot be read or written
     * @return ?ActionReplyDTO Always null: the message action answers with no domain reply
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case ChatSignalConstants::MESSAGE:
                if (!$dto instanceof MessageActionDTO) {
                    throw new InvalidActionPayloadException($action, MessageActionDTO::class, $dto);
                }
                $this->handleMessage($dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Routes main-page agent signals to the outbound moderation and publication handlers.
     *
     * @param AgentSignalData $data Wrapped moderation result or publication answer
     * @param string $sender Sender in full - source, then agent type, then index, as {@see SignalSource::describe()} spells it (unused)
     * @param string $name Moderation result or publication answer signal name
     * @throws AgentUnknownSignalException When signal name is not supported by this page
     * @throws LogicException When the payload type does not match the signal contract
     * @throws ValidationException When moderation rejects the message or is unavailable
     * @throws AgentException When moderation result does not match an active connection
     * @throws HilosException When the follow-up exposes registry, database, or runtime failure
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case ChatSignalConstants::MODERATION_RESULT:
                if (!$data->data instanceof ModerationResultSignalData) {
                    throw new LogicException(
                        ChatSignalConstants::MODERATION_RESULT . ' payload must be ' . ModerationResultSignalData::class,
                    );
                }
                $this->handleTextModerationResult($data->data);

                return;

            case ChatSignalConstants::ATTACHMENTS_PUBLISHED:
                if (!$data->data instanceof FilesPublishedSignalData) {
                    throw new LogicException(
                        ChatSignalConstants::ATTACHMENTS_PUBLISHED . ' payload must be ' . FilesPublishedSignalData::class,
                    );
                }
                $this->handleAttachmentsPublished($data->data);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Starts outbound moderation for a message with text, files, or both.
     *
     * Every file the message names must be a complete upload of this connection for the chat's
     * target, named once; the list is kept on the connection beside the text, so the files that
     * ride the message are the ones the person saw when sending it.
     *
     * @param MessageActionDTO $dto Parsed message action payload
     * @throws EmptyValueException When message has no non-empty text and no attachments
     * @throws ItemNotFoundForUpdateException When the WebSocket session or user runtime state is missing
     * @throws ValidationException When the user is rate-limited or already moderating, or a file is not ready or named twice
     * @throws HilosException When the uploads cannot be read or the runtime state cannot be written
     */
    private function handleMessage(MessageActionDTO $dto): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new ItemNotFoundForUpdateException('User session not found');
        }
        if (Hilos::$rt->selfConnection->userState === null) {
            throw new ItemNotFoundForUpdateException('User runtime state not found');
        }
        if (
            Hilos::$rt->selfConnection->outboundModerationPhase
            === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
        ) {
            throw new ValidationException('Another message is already being moderated');
        }
        if (
            microtime(true) - Hilos::$rt->selfConnection->userState->lastOutboundSubmittedAt
            < ChatUserState::MESSAGE_RATE_LIMIT_SECONDS - ChatUserState::MESSAGE_RATE_LIMIT_TOLERANCE_SECONDS
        ) {
            throw new ValidationException('Message rate limit is active');
        }

        if (count(array_unique($dto->attachments)) !== count($dto->attachments)) {
            throw new ValidationException('Attachment is listed twice');
        }
        foreach ($dto->attachments as $clientUploadId) {
            $upload = Hilos::$rt->hilosUploads->find(Hilos::$rt->selfConnection->acceptKey, $clientUploadId);
            if ($upload === null || $upload->target !== ChatAttachmentUploadTarget::NAME || $upload->phase !== UploadPhase::COMPLETE) {
                throw new ValidationException('Attachment is not ready');
            }
        }
        if (trim($dto->content) === '' && $dto->attachments === []) {
            throw new EmptyValueException('Message cannot be empty');
        }

        Hilos::$rt->selfConnection->userState->actions->recordOutboundSubmission();
        Hilos::$rt->selfConnection->actions->startOutboundModeration($dto->content, $dto->attachments);
    }

    /**
     * Applies outbound moderation: publish approved text, ask the registry to publish its files,
     * or expose a retryable failure state.
     *
     * Stale connection results fail the agent-signal contract and never publish a message. A
     * message with files stays under moderation until the registry answers
     * ({@see self::handleAttachmentsPublished()}).
     *
     * @param ModerationResultSignalData $result Uploader connection key, allow flag, message body, reason
     * @throws ValidationException When moderation rejects the message or is unavailable
     * @throws AgentException When result does not match an active connection
     * @throws LogicException When the files door is not created
     * @throws HilosException When the publication request, runtime writes, or event persistence fails
     */
    private function handleTextModerationResult(ModerationResultSignalData $result): void
    {
        if (Hilos::$rt->selfConnection === null) {
            throw new AgentException('Moderation result connection is stale');
        }

        if (!$result->allow) {
            $reason = $result->reason !== '' ? $result->reason : 'unknown';
            $phase = in_array($reason, ['service_unavailable', 'unknown'], true)
                ? ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE
                : ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_REJECTED;
            Hilos::$rt->selfConnection->actions->failOutboundModeration(
                $phase,
                $reason,
            );
            if ($phase === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_REJECTED) {
                $this->notifyMessageRejected(
                    Hilos::$rt->selfConnection->userId,
                    $result->message,
                    $reason,
                );
            }

            throw new ValidationException($reason);
        }

        if (Hilos::$rt->selfConnection->outboundModerationAttachments === []) {
            Hilos::$rt->selfConnection->actions->clearOutboundModeration();
            Hilos::$db->events->actions->addMessage($result->message, userId: Hilos::$rt->selfConnection->userId);

            return;
        }

        // The moderation stays checking until the registry answers - a matter of milliseconds.
        (Hilos::$files ?? throw new LogicException('The files door is not created'))->publishUploads(
            Hilos::$rt->selfConnection->acceptKey,
            ChatAttachmentUploadTarget::NAME,
            Hilos::$rt->selfConnection->outboundModerationAttachments,
            ChatSignalConstants::ATTACHMENTS_PUBLISHED,
        );
    }

    /**
     * Writes the approved message with its published files, or returns it to the person.
     *
     * An answer nobody waits for any more - the connection is gone, its moderation moved on, or
     * it waits for another list - is logged, and the files it published are removed at once
     * rather than left to the janitor for a day. A refusal turns the moderation unavailable with
     * the registry's sentence as the reason; the text returns to the composer, and the uploads
     * that are still alive stay attached. Otherwise the message is written with links to the
     * files in the order the person attached them, and the files are marked bound after it: a
     * message that fails to be written leaves them unbound, for the janitor.
     *
     * @param FilesPublishedSignalData $published Answer of the files registry
     * @throws LogicException When the files door is not created, or a checking connection carries no message
     * @throws HilosException When the registry request, runtime writes, or event persistence fails
     */
    private function handleAttachmentsPublished(FilesPublishedSignalData $published): void
    {
        $files = Hilos::$files ?? throw new LogicException('The files door is not created');
        if (
            Hilos::$rt->selfConnection === null
            || Hilos::$rt->selfConnection->outboundModerationPhase !== ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING
            || Hilos::$rt->selfConnection->outboundModerationAttachments !== $published->clientUploadIds
        ) {
            $this->logAgentInfo(
                "Attachments of {$published->acceptKey} answered for a message nobody waits for; removing "
                . count($published->fileIds) . ' published file(s)',
            );
            $files->remove($published->fileIds);

            return;
        }

        if ($published->error !== null) {
            Hilos::$rt->selfConnection->actions->failOutboundModeration(
                ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
                $published->error,
            );

            return;
        }

        $message = Hilos::$rt->selfConnection->outboundModerationMessage
            ?? throw new LogicException('A message under moderation carries no text');
        Hilos::$rt->selfConnection->actions->clearOutboundModeration();
        Hilos::$db->events->actions->addMessage($message, userId: Hilos::$rt->selfConnection->userId, fileIds: $published->fileIds);
        $files->markBound($published->fileIds);
    }

    /**
     * Notifies the author that moderation refused to publish their message.
     *
     * Only a verdict about the text notifies: an unavailable moderator is an
     * infrastructure failure, and there is nothing to tell the author about it. The
     * emit is best-effort with respect to the rejection - the action error raised
     * next reaches the author whatever happens to the notification.
     *
     * @param ?int $userId Author user id, or null when the connection carries none
     * @param string $message Rejected message text, kept so the author knows which one
     * @param string $reason Moderation reason
     */
    private function notifyMessageRejected(?int $userId, string $message, string $reason): void
    {
        if ($userId === null) {
            return;
        }

        try {
            Hilos::$notify?->emit(new NotificationDraft(
                userId: $userId,
                type: ChatNotificationType::MESSAGE_REJECTED,
                title: 'Your message was not published',
                severity: NotificationSeverity::WARNING,
                body: 'Moderation rejected it: ' . $reason,
                data: [
                    'reason' => $reason,
                    'message' => $message,
                ],
            ));
        } catch (HilosException $e) {
            $this->logAgentError(
                "Message rejection notification failed for userId={$userId}: {$e->getMessage()}",
            );
        }
    }
}
