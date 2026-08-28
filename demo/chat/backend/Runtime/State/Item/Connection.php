<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\HilosSessionConnection;

/**
 * Runtime row for one WebSocket connection (`acceptKey` is the collection id).
 *
 * Stands on the framework {@see HilosSessionConnection} base — the session stage,
 * because chat carries browser sessions — which owns the session triple
 * (acceptKey / sessionToken / userId) and the whole create/hydrate/serialize/diff
 * template. This subclass adds chat's own in-memory moderation, file upload
 * session, and progress UI state for this socket only, and reaches them through
 * the four hooks the base leaves it: {@see initOwn()}, {@see hydrateOwn()},
 * {@see ownToArray()}, {@see applyOwnDiff()}. Inbound RT updates arrive through
 * the base `applyDiff()`; local writes from item actions use typed properties and
 * `sync()`. Public string constants name row keys.
 */
final class Connection extends HilosSessionConnection
{
    public const string connectedAt = 'connectedAt';

    public const string outboundModerationPhase = 'outboundModerationPhase';
    public const string outboundModerationMessage = 'outboundModerationMessage';
    public const string outboundModerationReason = 'outboundModerationReason';
    public const string outboundModerationUpdatedAt = 'outboundModerationUpdatedAt';

    public const string renameModerationPhase = 'renameModerationPhase';
    public const string renameModerationName = 'renameModerationName';
    public const string renameModerationReason = 'renameModerationReason';
    public const string renameModerationUpdatedAt = 'renameModerationUpdatedAt';

    public const string fileSessionUploadId = 'fileSessionUploadId';
    public const string fileSessionDeclaredSize = 'fileSessionDeclaredSize';
    public const string fileSessionReceivedBytes = 'fileSessionReceivedBytes';
    public const string fileSessionQuarantineBasename = 'fileSessionQuarantineBasename';
    public const string fileSessionOriginalFilename = 'fileSessionOriginalFilename';
    public const string fileSessionMimeType = 'fileSessionMimeType';
    public const string fileSessionClientUploadId = 'fileSessionClientUploadId';
    public const string fileSessionNormalizedFilename = 'fileSessionNormalizedFilename';

    public const string fileUploadPhase = 'fileUploadPhase';
    public const string fileUploadClientUploadId = 'fileUploadClientUploadId';
    public const string fileUploadErrorCode = 'fileUploadErrorCode';
    public const string fileUploadErrorMessage = 'fileUploadErrorMessage';

    public const string fileProgressFilename = 'fileProgressFilename';
    public const string fileProgressUploadedBytes = 'fileProgressUploadedBytes';
    public const string fileProgressTotalBytes = 'fileProgressTotalBytes';

    public const string uploadProgressLastSentAt = 'uploadProgressLastSentAt';

    /** Unix time when the socket was registered. */
    private(set) int $connectedAt = 0;

    /** Moderation phase: checking, rejected, unavailable, or none while the socket is clear. */
    public string $outboundModerationPhase = ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE;

    /**
     * Submitted message text associated with the moderation state — empty when
     * the submit carried attachments and no text — or null while none is.
     */
    public ?string $outboundModerationMessage = null;

    /** Moderation rejection or unavailable reason, or null while the verdict names none. */
    public ?string $outboundModerationReason = null;

    /** Unix time of last moderation state update. */
    public int $outboundModerationUpdatedAt = 0;

    /** Rename moderation phase: checking, rejected, unavailable, or none while the socket is clear. */
    public string $renameModerationPhase = ConnectionRuntimeConstants::RENAME_MODERATION_PHASE_NONE;

    /** Requested display name associated with rename moderation, or null while none is. */
    public ?string $renameModerationName = null;

    /** Rename moderation rejection or unavailable reason, or null while the verdict names none. */
    public ?string $renameModerationReason = null;

    /** Unix time of last rename moderation state update. */
    public int $renameModerationUpdatedAt = 0;

    /** Active upload id or null. */
    public ?string $fileSessionUploadId = null;

    /** Declared total size for current upload. */
    public int $fileSessionDeclaredSize = 0;

    /** Bytes appended so far for current upload. */
    public int $fileSessionReceivedBytes = 0;

    /** Quarantine file basename, or null when no upload session is open. */
    public ?string $fileSessionQuarantineBasename = null;

    /** Client original filename for session, or null when no upload session is open. */
    public ?string $fileSessionOriginalFilename = null;

    /** MIME type for session, or null when no upload session is open. */
    public ?string $fileSessionMimeType = null;

    /** Client-side upload correlation id, or null when no upload session is open. */
    public ?string $fileSessionClientUploadId = null;

    /** Normalized basename for duplicate-name checks, or null when no upload session is open. */
    public ?string $fileSessionNormalizedFilename = null;

    /** Upload UI phase: ready, uploading, failed, or idle while no upload is running. */
    public string $fileUploadPhase = ConnectionRuntimeConstants::FILE_UPLOAD_PHASE_IDLE;

    /** Client-side upload correlation id for ready/failed upload state. */
    public ?string $fileUploadClientUploadId = null;

    /** Upload failure code shown through self-connection state. */
    public ?string $fileUploadErrorCode = null;

    /** Upload failure message shown through self-connection state. */
    public ?string $fileUploadErrorMessage = null;

    /** Progress bar filename or null. */
    public ?string $fileProgressFilename = null;

    /** Bytes for upload progress UI. */
    public int $fileProgressUploadedBytes = 0;

    /** Total for upload progress UI. */
    public int $fileProgressTotalBytes = 0;

    /** Last upload-progress browser notify time for throttle (microtime). */
    public float $uploadProgressLastSentAt = 0.0;

    /**
     * Stamps the moment the socket was registered; every other chat field opens
     * on the value its declaration already carries.
     */
    protected function initOwn(): void
    {
        $this->connectedAt = time();
    }

    /**
     * The row is written by {@see ownToArray()} on another worker, so every key it
     * declares is required here and a phase field arrives as the empty string only
     * when that is the phase — the value its own `*_NONE` constant names.
     *
     * An empty string is a value in every optional field below, never a spelling of
     * absence: absence is `null`, and only this class writes these keys. A message
     * submitted with attachments and no text is the field that makes the difference
     * visible — read as "no message", it would leave the moderator's phase checking
     * forever and every later submit refused as one already under moderation.
     *
     * @param array<string, mixed> $row Serialized runtime row (string keys match this class field constants)
     * @throws InvalidFormatException When the row is missing a field of this socket's chat state
     */
    protected function hydrateOwn(array $row): void
    {
        $this->connectedAt = self::requireInt($row, self::connectedAt);
        $this->outboundModerationPhase = self::requireString($row, self::outboundModerationPhase);
        $this->outboundModerationMessage = self::optionalString($row, self::outboundModerationMessage);
        $this->outboundModerationReason = self::optionalString($row, self::outboundModerationReason);
        $this->outboundModerationUpdatedAt = self::requireInt($row, self::outboundModerationUpdatedAt);
        $this->renameModerationPhase = self::requireString($row, self::renameModerationPhase);
        $this->renameModerationName = self::optionalString($row, self::renameModerationName);
        $this->renameModerationReason = self::optionalString($row, self::renameModerationReason);
        $this->renameModerationUpdatedAt = self::requireInt($row, self::renameModerationUpdatedAt);
        $this->fileSessionUploadId = self::optionalString($row, self::fileSessionUploadId);
        $this->fileSessionDeclaredSize = self::requireInt($row, self::fileSessionDeclaredSize);
        $this->fileSessionReceivedBytes = self::requireInt($row, self::fileSessionReceivedBytes);
        $this->fileSessionQuarantineBasename = self::optionalString($row, self::fileSessionQuarantineBasename);
        $this->fileSessionOriginalFilename = self::optionalString($row, self::fileSessionOriginalFilename);
        $this->fileSessionMimeType = self::optionalString($row, self::fileSessionMimeType);
        $this->fileSessionClientUploadId = self::optionalString($row, self::fileSessionClientUploadId);
        $this->fileSessionNormalizedFilename = self::optionalString($row, self::fileSessionNormalizedFilename);
        $this->fileUploadPhase = self::requireString($row, self::fileUploadPhase);
        $this->fileUploadClientUploadId = self::optionalString($row, self::fileUploadClientUploadId);
        $this->fileUploadErrorCode = self::optionalString($row, self::fileUploadErrorCode);
        $this->fileUploadErrorMessage = self::optionalString($row, self::fileUploadErrorMessage);
        $this->fileProgressFilename = self::optionalString($row, self::fileProgressFilename);
        $this->fileProgressUploadedBytes = self::requireInt($row, self::fileProgressUploadedBytes);
        $this->fileProgressTotalBytes = self::requireInt($row, self::fileProgressTotalBytes);
        $this->uploadProgressLastSentAt = self::requireFloat($row, self::uploadProgressLastSentAt);
    }

    /**
     * Runtime collection key for connection rows.
     *
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return ChatRtContext::connections;
    }

    /**
     * A diff carries only the fields that changed, so an absent key means the
     * field was not touched; a key that is present is read at its declared type.
     *
     * @param array<string, mixed> $diff Partial update; keys are public `* = 'fieldName'` constants on this class
     * @throws InvalidFormatException When a field the diff does carry holds the wrong type
     */
    protected function applyOwnDiff(array $diff): void
    {
        $this->connectedAt = self::patchInt($diff, self::connectedAt, $this->connectedAt);
        $this->outboundModerationPhase = self::patchString($diff, self::outboundModerationPhase, $this->outboundModerationPhase);
        $this->outboundModerationMessage = self::patchOptionalString($diff, self::outboundModerationMessage, $this->outboundModerationMessage);
        $this->outboundModerationReason = self::patchOptionalString($diff, self::outboundModerationReason, $this->outboundModerationReason);
        $this->outboundModerationUpdatedAt = self::patchInt($diff, self::outboundModerationUpdatedAt, $this->outboundModerationUpdatedAt);
        $this->renameModerationPhase = self::patchString($diff, self::renameModerationPhase, $this->renameModerationPhase);
        $this->renameModerationName = self::patchOptionalString($diff, self::renameModerationName, $this->renameModerationName);
        $this->renameModerationReason = self::patchOptionalString($diff, self::renameModerationReason, $this->renameModerationReason);
        $this->renameModerationUpdatedAt = self::patchInt($diff, self::renameModerationUpdatedAt, $this->renameModerationUpdatedAt);
        $this->fileSessionUploadId = self::patchOptionalString($diff, self::fileSessionUploadId, $this->fileSessionUploadId);
        $this->fileSessionDeclaredSize = self::patchInt($diff, self::fileSessionDeclaredSize, $this->fileSessionDeclaredSize);
        $this->fileSessionReceivedBytes = self::patchInt($diff, self::fileSessionReceivedBytes, $this->fileSessionReceivedBytes);
        $this->fileSessionQuarantineBasename = self::patchOptionalString(
            $diff,
            self::fileSessionQuarantineBasename,
            $this->fileSessionQuarantineBasename,
        );
        $this->fileSessionOriginalFilename = self::patchOptionalString($diff, self::fileSessionOriginalFilename, $this->fileSessionOriginalFilename);
        $this->fileSessionMimeType = self::patchOptionalString($diff, self::fileSessionMimeType, $this->fileSessionMimeType);
        $this->fileSessionClientUploadId = self::patchOptionalString($diff, self::fileSessionClientUploadId, $this->fileSessionClientUploadId);
        $this->fileSessionNormalizedFilename = self::patchOptionalString(
            $diff,
            self::fileSessionNormalizedFilename,
            $this->fileSessionNormalizedFilename,
        );
        $this->fileUploadPhase = self::patchString($diff, self::fileUploadPhase, $this->fileUploadPhase);
        $this->fileUploadClientUploadId = self::patchOptionalString($diff, self::fileUploadClientUploadId, $this->fileUploadClientUploadId);
        $this->fileUploadErrorCode = self::patchOptionalString($diff, self::fileUploadErrorCode, $this->fileUploadErrorCode);
        $this->fileUploadErrorMessage = self::patchOptionalString($diff, self::fileUploadErrorMessage, $this->fileUploadErrorMessage);
        $this->fileProgressFilename = self::patchOptionalString($diff, self::fileProgressFilename, $this->fileProgressFilename);
        $this->fileProgressUploadedBytes = self::patchInt($diff, self::fileProgressUploadedBytes, $this->fileProgressUploadedBytes);
        $this->fileProgressTotalBytes = self::patchInt($diff, self::fileProgressTotalBytes, $this->fileProgressTotalBytes);
        $this->uploadProgressLastSentAt = self::patchFloat($diff, self::uploadProgressLastSentAt, $this->uploadProgressLastSentAt);
    }

    /**
     * @return array<string, mixed> Chat's own fields of the row
     */
    protected function ownToArray(): array
    {
        return [
            self::connectedAt => $this->connectedAt,
            self::outboundModerationPhase => $this->outboundModerationPhase,
            self::outboundModerationMessage => $this->outboundModerationMessage,
            self::outboundModerationReason => $this->outboundModerationReason,
            self::outboundModerationUpdatedAt => $this->outboundModerationUpdatedAt,
            self::renameModerationPhase => $this->renameModerationPhase,
            self::renameModerationName => $this->renameModerationName,
            self::renameModerationReason => $this->renameModerationReason,
            self::renameModerationUpdatedAt => $this->renameModerationUpdatedAt,
            self::fileSessionUploadId => $this->fileSessionUploadId,
            self::fileSessionDeclaredSize => $this->fileSessionDeclaredSize,
            self::fileSessionReceivedBytes => $this->fileSessionReceivedBytes,
            self::fileSessionQuarantineBasename => $this->fileSessionQuarantineBasename,
            self::fileSessionOriginalFilename => $this->fileSessionOriginalFilename,
            self::fileSessionMimeType => $this->fileSessionMimeType,
            self::fileSessionClientUploadId => $this->fileSessionClientUploadId,
            self::fileSessionNormalizedFilename => $this->fileSessionNormalizedFilename,
            self::fileUploadPhase => $this->fileUploadPhase,
            self::fileUploadClientUploadId => $this->fileUploadClientUploadId,
            self::fileUploadErrorCode => $this->fileUploadErrorCode,
            self::fileUploadErrorMessage => $this->fileUploadErrorMessage,
            self::fileProgressFilename => $this->fileProgressFilename,
            self::fileProgressUploadedBytes => $this->fileProgressUploadedBytes,
            self::fileProgressTotalBytes => $this->fileProgressTotalBytes,
            self::uploadProgressLastSentAt => $this->uploadProgressLastSentAt,
        ];
    }
}
