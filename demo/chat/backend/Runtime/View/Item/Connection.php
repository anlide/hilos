<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\View\Item\User;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Actions\Item\ConnectionActions;
use Demo\Chat\Runtime\View\Collection\AttachmentDrafts;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\HilosSessionConnection;

/**
 * Read-only runtime item for a connection state row plus virtual user links.
 *
 * Stands on the framework {@see HilosSessionConnection} base — the session stage,
 * which reads the accept key, the session token, the bound user and the item's
 * actions — and adds chat's own per-socket fields and the virtual links that turn
 * a bound user id into rows. Use `Hilos::$rt->connections` for collection access.
 * Per-connection writes go through this item's actions.
 *
 * @extends HilosSessionConnection<StateConnection>
 *
 * @property-read int $connectedAt Unix timestamp when connected
 * @property-read string $outboundModerationPhase Current moderation phase
 * @property-read ?string $outboundModerationMessage Submitted message text, or null when none is
 * @property-read ?string $outboundModerationReason Rejection or unavailable reason, or null
 * @property-read int $outboundModerationUpdatedAt Last moderation update unix time
 * @property-read string $renameModerationPhase Current rename moderation phase
 * @property-read ?string $renameModerationName Requested display name, or null when none is
 * @property-read ?string $renameModerationReason Rename rejection or unavailable reason, or null
 * @property-read int $renameModerationUpdatedAt Last rename moderation update unix time
 * @property-read ?string $fileSessionUploadId Active binary upload id or null
 * @property-read int $fileSessionDeclaredSize Declared total bytes for current upload session
 * @property-read int $fileSessionReceivedBytes Bytes received for current upload session
 * @property-read ?string $fileSessionQuarantineBasename Quarantine basename (.part), or null when idle
 * @property-read ?string $fileSessionOriginalFilename Original filename for current session, or null when idle
 * @property-read ?string $fileSessionMimeType MIME type for current session, or null when idle
 * @property-read ?string $fileSessionClientUploadId Client upload correlation id, or null when idle
 * @property-read ?string $fileSessionNormalizedFilename Normalized basename for dedup, or null when idle
 * @property-read string $fileUploadPhase Upload UI phase, or empty when idle
 * @property-read ?string $fileUploadClientUploadId Upload UI client correlation id
 * @property-read ?string $fileUploadErrorCode Upload failure code
 * @property-read ?string $fileUploadErrorMessage Upload failure message
 * @property-read ?string $fileProgressFilename Progress bar filename or null
 * @property-read int $fileProgressUploadedBytes Progress uploaded bytes
 * @property-read int $fileProgressTotalBytes Progress total bytes
 * @property-read float $uploadProgressLastSentAt Microtime of last upload-progress browser notification
 * @property-read ?User $user User row or null if not found in DB view
 * @property-read ?ChatUserState $userState Runtime user state row or null if not found
 * @property-read AttachmentDrafts $attachmentDrafts Uploaded drafts owned by this connection
 * @property-read ConnectionActions $actions Write operations for this connection
 */
final class Connection extends HilosSessionConnection
{
    /**
     * @param StateConnection $state Backing state, same as parent contract
     */
    public function __construct(StateConnection $state)
    {
        parent::__construct($state);
    }

    /**
     * Delegates chat's own keys to the backing state; virtual links load DB user, runtime user
     * state, and drafts. The base fields and the item actions are resolved by the framework base.
     *
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
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
            ChatDbContext::user => $this->_state->userId !== null ? Hilos::$db->users[$this->_state->userId] : null,
            ConnectionRuntimeConstants::userState => $this->_state->userId !== null
                ? Hilos::$rt->userStates[$this->_state->userId]
                : null,
            ConnectionRuntimeConstants::attachmentDrafts => Hilos::$rt->attachmentDrafts->forAcceptKey(
                $this->_state->acceptKey,
            ),
            default => parent::__get($name),
        };
    }
}
