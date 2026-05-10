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
 * @property-read string $outboundModerationPhase Current moderation phase
 * @property-read string $outboundModerationMessage Submitted message text
 * @property-read string $outboundModerationReason Rejection or unavailable reason
 * @property-read int $outboundModerationUpdatedAt Last moderation update unix time
 * @property-read string $renameModerationPhase Current rename moderation phase
 * @property-read string $renameModerationName Requested display name
 * @property-read string $renameModerationReason Rename rejection or unavailable reason
 * @property-read int $renameModerationUpdatedAt Last rename moderation update unix time
 * @property-read ?string $fileSessionUploadId Active binary upload id or null
 * @property-read int $fileSessionDeclaredSize Declared total bytes for current upload session
 * @property-read int $fileSessionReceivedBytes Bytes received for current upload session
 * @property-read string $fileSessionQuarantineBasename Quarantine basename (.part)
 * @property-read string $fileSessionOriginalFilename Original filename for current session
 * @property-read string $fileSessionMimeType MIME type for current session
 * @property-read string $fileSessionClientUploadId Client upload correlation id
 * @property-read string $fileSessionNormalizedFilename Normalized basename for dedup
 * @property-read string $fileUploadPhase Upload UI phase, or empty when idle
 * @property-read ?string $fileUploadClientUploadId Upload UI client correlation id
 * @property-read ?string $fileUploadErrorCode Upload failure code
 * @property-read ?string $fileUploadErrorMessage Upload failure message
 * @property-read ?string $fileProgressFilename Progress bar filename or null
 * @property-read int $fileProgressUploadedBytes Progress uploaded bytes
 * @property-read int $fileProgressTotalBytes Progress total bytes
 * @property-read float $uploadProgressLastSentAt Microtime of last upload-progress projection notification
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
     * No visible rename moderation state.
     */
    public const string RENAME_MODERATION_PHASE_NONE = '';

    /**
     * Moderation phase while a user-initiated rename is being checked.
     */
    public const string RENAME_MODERATION_PHASE_CHECKING = 'checking';

    /**
     * Moderation phase for a rejected display name.
     */
    public const string RENAME_MODERATION_PHASE_REJECTED = 'rejected';

    /**
     * Moderation phase for unavailable rename moderation.
     */
    public const string RENAME_MODERATION_PHASE_UNAVAILABLE = 'unavailable';

    /**
     * No upload state visible to the frontend.
     */
    public const string FILE_UPLOAD_PHASE_IDLE = '';

    /**
     * Backend accepted metadata and the client may stream binary frames.
     */
    public const string FILE_UPLOAD_PHASE_READY = 'ready';

    /**
     * The client is streaming binary frames for the active upload.
     */
    public const string FILE_UPLOAD_PHASE_UPLOADING = 'uploading';

    /**
     * Upload init or binary streaming failed; retry is allowed.
     */
    public const string FILE_UPLOAD_PHASE_FAILED = 'failed';

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
        return match ($name) {
            StateConnection::acceptKey => $this->_state->acceptKey,
            StateConnection::userId => $this->_state->userId,
            StateConnection::connectedAt => $this->_state->connectedAt,
            StateConnection::outboundModerationPhase => $this->_state->outboundModerationPhase,
            StateConnection::outboundModerationMessage => $this->_state->outboundModerationMessage,
            StateConnection::outboundModerationReason => $this->_state->outboundModerationReason,
            StateConnection::outboundModerationUpdatedAt => $this->_state->outboundModerationUpdatedAt,
            StateConnection::renameModerationPhase => $this->_state->renameModerationPhase,
            StateConnection::renameModerationName => $this->_state->renameModerationName,
            StateConnection::renameModerationReason => $this->_state->renameModerationReason,
            StateConnection::renameModerationUpdatedAt => $this->_state->renameModerationUpdatedAt,
            StateConnection::fileSessionUploadId => $this->_state->fileSessionUploadId,
            StateConnection::fileSessionDeclaredSize => $this->_state->fileSessionDeclaredSize,
            StateConnection::fileSessionReceivedBytes => $this->_state->fileSessionReceivedBytes,
            StateConnection::fileSessionQuarantineBasename => $this->_state->fileSessionQuarantineBasename,
            StateConnection::fileSessionOriginalFilename => $this->_state->fileSessionOriginalFilename,
            StateConnection::fileSessionMimeType => $this->_state->fileSessionMimeType,
            StateConnection::fileSessionClientUploadId => $this->_state->fileSessionClientUploadId,
            StateConnection::fileSessionNormalizedFilename => $this->_state->fileSessionNormalizedFilename,
            StateConnection::fileUploadPhase => $this->_state->fileUploadPhase,
            StateConnection::fileUploadClientUploadId => $this->_state->fileUploadClientUploadId,
            StateConnection::fileUploadErrorCode => $this->_state->fileUploadErrorCode,
            StateConnection::fileUploadErrorMessage => $this->_state->fileUploadErrorMessage,
            StateConnection::fileProgressFilename => $this->_state->fileProgressFilename,
            StateConnection::fileProgressUploadedBytes => $this->_state->fileProgressUploadedBytes,
            StateConnection::fileProgressTotalBytes => $this->_state->fileProgressTotalBytes,
            StateConnection::uploadProgressLastSentAt => $this->_state->uploadProgressLastSentAt,
            RtItem::actions => $this->getItemActions(),
            DbChatContext::user => Hilos::$db->users[$this->_state->userId],
            self::userState => Hilos::$rt->userStates[$this->_state->userId],
            self::attachmentDrafts => Hilos::$rt->attachmentDrafts->forAcceptKey($this->_state->acceptKey),
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row (same as {@see StateConnection::toArray()})
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
