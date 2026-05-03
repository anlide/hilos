<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Item\User;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Actions\Collection\ConnectionsActions;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Actions\Item\ConnectionActions;
use Demo\Chat\Runtime\View\Collection\AttachmentDrafts;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\RtItem;

/**
 * Read-only {@see RtItem} over {@see StateConnection}: every state field plus virtual user links.
 *
 * Use {@see Hilos::$rt->connections} for access in agents/pages. Collection writes: {@see ConnectionsActions};
 * per-connection writes (e.g. file upload session): {@see ConnectionActions}.
 * File-runtime mutations: {@see ConnectionActions}; collection-level helpers: {@see ConnectionsActions}.
 *
 * @extends RtItem<StateConnection>
 *
 * @property-read string $acceptKey WebSocket accept key
 * @property-read int $userId User ID for this connection
 * @property-read int $connectedAt Unix timestamp when connected
 * @property-read string $outboundModerationRequestId Current moderation request id
 * @property-read string $outboundModerationPhase Current moderation phase
 * @property-read string $outboundModerationMessage Submitted message text
 * @property-read list<string> $outboundModerationAttachmentDraftIds Submitted attachment draft ids
 * @property-read string $outboundModerationReason Rejection or unavailable reason
 * @property-read int $outboundModerationUpdatedAt Last moderation update unix time
 * @property-read ?string $fileSessionUploadId Active binary upload id or null
 * @property-read int $fileSessionDeclaredSize Declared total bytes for current upload session
 * @property-read int $fileSessionReceivedBytes Bytes received for current upload session
 * @property-read string $fileSessionQuarantineBasename Quarantine basename (.part)
 * @property-read string $fileSessionOriginalFilename Original filename for current session
 * @property-read string $fileSessionMimeType MIME type for current session
 * @property-read string $fileSessionClientUploadId Client upload correlation id
 * @property-read string $fileSessionNormalizedFilename Normalized basename for dedup
 * @property-read ?string $fileProgressFilename Progress bar filename or null
 * @property-read int $fileProgressUploadedBytes Progress uploaded bytes
 * @property-read int $fileProgressTotalBytes Progress total bytes
 * @property-read float $uploadProgressLastSentAt Microtime of last upload-progress baseline (READY or progress_update)
 * @property-read ?User $user User row or null if not found in DB view
 * @property-read ?ChatUserState $userState Runtime user state row or null if not found
 * @property-read AttachmentDrafts $attachmentDrafts Uploaded drafts owned by this connection
 * @property-read ConnectionActions $actions Write operations for this connection
 */
final class Connection extends RtItem
{
    public const string userState = 'userState';
    public const string attachmentDrafts = 'attachmentDrafts';

    /**
     * No visible moderation state.
     */
    public const string OUTBOUND_MODERATION_PHASE_NONE = '';

    /**
     * Moderation phase while an outbound user message is being checked.
     */
    public const string OUTBOUND_MODERATION_PHASE_CHECKING = 'checking';

    /**
     * Moderation phase for a user-retryable rejected message.
     */
    public const string OUTBOUND_MODERATION_PHASE_REJECTED = 'rejected';

    /**
     * Moderation phase for unavailable moderation or missing attachment state.
     */
    public const string OUTBOUND_MODERATION_PHASE_UNAVAILABLE = 'unavailable';

    /**
     * @param StateConnection $state Backing state (by reference, same as parent contract)
     */
    public function __construct(StateConnection &$state)
    {
        parent::__construct($state);
    }

    /**
     * Delegates known keys to the backing state; virtual links load DB user, runtime user state, and drafts.
     *
     * @throws RtItemActionsClassException
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): array|string|int|float|User|ChatUserState|AttachmentDrafts|null|ConnectionActions
    {
        /** @var StateConnection $state */
        $state = $this->_state;

        return match ($name) {
            StateConnection::acceptKey => $state->acceptKey,
            StateConnection::userId => $state->userId,
            StateConnection::connectedAt => $state->connectedAt,
            StateConnection::outboundModerationRequestId => $state->outboundModerationRequestId,
            StateConnection::outboundModerationPhase => $state->outboundModerationPhase,
            StateConnection::outboundModerationMessage => $state->outboundModerationMessage,
            StateConnection::outboundModerationAttachmentDraftIds => $state->outboundModerationAttachmentDraftIds,
            StateConnection::outboundModerationReason => $state->outboundModerationReason,
            StateConnection::outboundModerationUpdatedAt => $state->outboundModerationUpdatedAt,
            StateConnection::fileSessionUploadId => $state->fileSessionUploadId,
            StateConnection::fileSessionDeclaredSize => $state->fileSessionDeclaredSize,
            StateConnection::fileSessionReceivedBytes => $state->fileSessionReceivedBytes,
            StateConnection::fileSessionQuarantineBasename => $state->fileSessionQuarantineBasename,
            StateConnection::fileSessionOriginalFilename => $state->fileSessionOriginalFilename,
            StateConnection::fileSessionMimeType => $state->fileSessionMimeType,
            StateConnection::fileSessionClientUploadId => $state->fileSessionClientUploadId,
            StateConnection::fileSessionNormalizedFilename => $state->fileSessionNormalizedFilename,
            StateConnection::fileProgressFilename => $state->fileProgressFilename,
            StateConnection::fileProgressUploadedBytes => $state->fileProgressUploadedBytes,
            StateConnection::fileProgressTotalBytes => $state->fileProgressTotalBytes,
            StateConnection::uploadProgressLastSentAt => $state->uploadProgressLastSentAt,
            RtItem::actions => $this->getItemActions(),
            DbChatContext::user => Hilos::$db->users[$state->userId] ?? null,
            self::userState => Hilos::$rt->userStates[$state->userId] ?? null,
            self::attachmentDrafts => Hilos::$rt->attachmentDrafts->forAcceptKey($state->acceptKey),
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row (same as {@see StateConnection::toArray()})
     */
    public function toArray(): array
    {
        /** @var StateConnection $state */
        $state = $this->_state;

        return $state->toArray();
    }
}
