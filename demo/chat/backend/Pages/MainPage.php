<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Files\ChatAttachmentUploadTarget;
use Demo\Chat\Pages\DTO\Main\MessageActionDTO;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Item\ChatUserState;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Files\Upload\UploadPhase;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosUpload;

/**
 * Validates chat message submissions and starts moderation in the owning agent's process.
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
        $this->agent->startMessageModeration(Hilos::$rt->selfConnection->acceptKey);
    }
}
