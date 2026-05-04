<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Actions\Item\ConnectionActions;
use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Runtime\State\Item\RtState;

/**
 * Runtime row for one WebSocket connection (`acceptKey` is the collection id).
 *
 * Holds transport metadata plus in-memory moderation, file upload session, and progress UI for this socket only.
 * Inbound RT updates use {@see applyDiff()}; local writes from item actions use typed properties + {@see sync()}.
 * Public string constants name row keys.
 */
final class Connection extends RtState
{
    public const string acceptKey = 'acceptKey';
    public const string userId = 'userId';
    public const string connectedAt = 'connectedAt';

    public const string outboundModerationRequestId = 'outboundModerationRequestId';
    public const string outboundModerationPhase = 'outboundModerationPhase';
    public const string outboundModerationMessage = 'outboundModerationMessage';
    public const string outboundModerationReason = 'outboundModerationReason';
    public const string outboundModerationUpdatedAt = 'outboundModerationUpdatedAt';

    public const string fileSessionUploadId = 'fileSessionUploadId';
    public const string fileSessionDeclaredSize = 'fileSessionDeclaredSize';
    public const string fileSessionReceivedBytes = 'fileSessionReceivedBytes';
    public const string fileSessionQuarantineBasename = 'fileSessionQuarantineBasename';
    public const string fileSessionOriginalFilename = 'fileSessionOriginalFilename';
    public const string fileSessionMimeType = 'fileSessionMimeType';
    public const string fileSessionClientUploadId = 'fileSessionClientUploadId';
    public const string fileSessionNormalizedFilename = 'fileSessionNormalizedFilename';

    public const string fileProgressFilename = 'fileProgressFilename';
    public const string fileProgressUploadedBytes = 'fileProgressUploadedBytes';
    public const string fileProgressTotalBytes = 'fileProgressTotalBytes';

    public const string uploadProgressLastSentAt = 'uploadProgressLastSentAt';

    /** WebSocket accept key (primary id). */
    public private(set) string $acceptKey = '';

    /** Owning database user id. */
    public private(set) int $userId = 0;

    /** Unix time when the socket was registered. */
    public private(set) int $connectedAt = 0;

    /** Current moderation request id, or empty string when no visible moderation state exists. */
    public string $outboundModerationRequestId = '';

    /** Moderation phase: checking, rejected, unavailable, or empty when clear. */
    public string $outboundModerationPhase = '';

    /** Submitted message text associated with the moderation state. */
    public string $outboundModerationMessage = '';

    /** Moderation rejection or unavailable reason. */
    public string $outboundModerationReason = '';

    /** Unix time of last moderation state update. */
    public int $outboundModerationUpdatedAt = 0;

    /** Active upload id or null. */
    public ?string $fileSessionUploadId = null;

    /** Declared total size for current upload. */
    public int $fileSessionDeclaredSize = 0;

    /** Bytes appended so far for current upload. */
    public int $fileSessionReceivedBytes = 0;

    /** Quarantine file basename. */
    public string $fileSessionQuarantineBasename = '';

    /** Client original filename for session. */
    public string $fileSessionOriginalFilename = '';

    /** MIME type for session. */
    public string $fileSessionMimeType = '';

    /** Client-side upload correlation id. */
    public string $fileSessionClientUploadId = '';

    /** Normalized basename for duplicate-name checks. */
    public string $fileSessionNormalizedFilename = '';

    /** Progress bar filename or null. */
    public ?string $fileProgressFilename = null;

    /** Bytes for upload progress UI. */
    public int $fileProgressUploadedBytes = 0;

    /** Total for upload progress UI. */
    public int $fileProgressTotalBytes = 0;

    /** Last FILE_UPLOAD_READY or FILE_UPLOAD_PROGRESS_UPDATE for throttle (microtime). */
    public float $uploadProgressLastSentAt = 0.0;

    /**
     * @param string $acceptKey WebSocket accept key (unique identifier)
     * @param int $userId User ID
     */
    public static function create(string $acceptKey, int $userId): static
    {
        $instance = new static();
        $instance->acceptKey = $acceptKey;
        $instance->userId = $userId;
        $instance->connectedAt = time();
        $instance->outboundModerationRequestId = '';
        $instance->outboundModerationPhase = '';
        $instance->outboundModerationMessage = '';
        $instance->outboundModerationReason = '';
        $instance->outboundModerationUpdatedAt = 0;
        $instance->fileSessionUploadId = null;
        $instance->fileSessionDeclaredSize = 0;
        $instance->fileSessionReceivedBytes = 0;
        $instance->fileSessionQuarantineBasename = '';
        $instance->fileSessionOriginalFilename = '';
        $instance->fileSessionMimeType = '';
        $instance->fileSessionClientUploadId = '';
        $instance->fileSessionNormalizedFilename = '';
        $instance->fileProgressFilename = null;
        $instance->fileProgressUploadedBytes = 0;
        $instance->fileProgressTotalBytes = 0;
        $instance->uploadProgressLastSentAt = 0.0;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (string keys match this class field constants)
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->acceptKey = (string)($row[self::acceptKey] ?? '');
        $instance->userId = (int)($row[self::userId] ?? 0);
        $instance->connectedAt = (int)($row[self::connectedAt] ?? time());
        $instance->outboundModerationRequestId = (string)($row[self::outboundModerationRequestId] ?? '');
        $instance->outboundModerationPhase = (string)($row[self::outboundModerationPhase] ?? '');
        $instance->outboundModerationMessage = (string)($row[self::outboundModerationMessage] ?? '');
        $instance->outboundModerationReason = (string)($row[self::outboundModerationReason] ?? '');
        $instance->outboundModerationUpdatedAt = (int)($row[self::outboundModerationUpdatedAt] ?? 0);
        $uid = $row[self::fileSessionUploadId] ?? null;
        $instance->fileSessionUploadId = is_string($uid) && $uid !== '' ? $uid : null;
        $instance->fileSessionDeclaredSize = (int)($row[self::fileSessionDeclaredSize] ?? 0);
        $instance->fileSessionReceivedBytes = (int)($row[self::fileSessionReceivedBytes] ?? 0);
        $instance->fileSessionQuarantineBasename = (string)($row[self::fileSessionQuarantineBasename] ?? '');
        $instance->fileSessionOriginalFilename = (string)($row[self::fileSessionOriginalFilename] ?? '');
        $instance->fileSessionMimeType = (string)($row[self::fileSessionMimeType] ?? '');
        $instance->fileSessionClientUploadId = (string)($row[self::fileSessionClientUploadId] ?? '');
        $instance->fileSessionNormalizedFilename = (string)($row[self::fileSessionNormalizedFilename] ?? '');
        $pfn = $row[self::fileProgressFilename] ?? null;
        $instance->fileProgressFilename = is_string($pfn) && $pfn !== '' ? $pfn : null;
        $instance->fileProgressUploadedBytes = (int)($row[self::fileProgressUploadedBytes] ?? 0);
        $instance->fileProgressTotalBytes = (int)($row[self::fileProgressTotalBytes] ?? 0);
        $instance->uploadProgressLastSentAt = (float)($row[self::uploadProgressLastSentAt] ?? 0.0);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    public static function getRtCollectionKey(): string
    {
        return RtChatContext::connections;
    }

    /**
     * @param array<string, mixed> $diff Partial update; keys are public `* = 'fieldName'` constants on this class
     */
    public function applyDiff(array $diff): void
    {
        if (isset($diff[self::userId])) {
            $this->userId = (int)$diff[self::userId];
        }
        if (isset($diff[self::connectedAt])) {
            $this->connectedAt = (int)$diff[self::connectedAt];
        }
        if (isset($diff[self::outboundModerationRequestId])) {
            $this->outboundModerationRequestId = (string)$diff[self::outboundModerationRequestId];
        }
        if (isset($diff[self::outboundModerationPhase])) {
            $this->outboundModerationPhase = (string)$diff[self::outboundModerationPhase];
        }
        if (isset($diff[self::outboundModerationMessage])) {
            $this->outboundModerationMessage = (string)$diff[self::outboundModerationMessage];
        }
        if (isset($diff[self::outboundModerationReason])) {
            $this->outboundModerationReason = (string)$diff[self::outboundModerationReason];
        }
        if (isset($diff[self::outboundModerationUpdatedAt])) {
            $this->outboundModerationUpdatedAt = (int)$diff[self::outboundModerationUpdatedAt];
        }
        if (array_key_exists(self::fileSessionUploadId, $diff)) {
            $v = $diff[self::fileSessionUploadId];
            $this->fileSessionUploadId = is_string($v) && $v !== '' ? $v : null;
        }
        if (isset($diff[self::fileSessionDeclaredSize])) {
            $this->fileSessionDeclaredSize = (int)$diff[self::fileSessionDeclaredSize];
        }
        if (isset($diff[self::fileSessionReceivedBytes])) {
            $this->fileSessionReceivedBytes = (int)$diff[self::fileSessionReceivedBytes];
        }
        if (isset($diff[self::fileSessionQuarantineBasename])) {
            $this->fileSessionQuarantineBasename = (string)$diff[self::fileSessionQuarantineBasename];
        }
        if (isset($diff[self::fileSessionOriginalFilename])) {
            $this->fileSessionOriginalFilename = (string)$diff[self::fileSessionOriginalFilename];
        }
        if (isset($diff[self::fileSessionMimeType])) {
            $this->fileSessionMimeType = (string)$diff[self::fileSessionMimeType];
        }
        if (isset($diff[self::fileSessionClientUploadId])) {
            $this->fileSessionClientUploadId = (string)$diff[self::fileSessionClientUploadId];
        }
        if (isset($diff[self::fileSessionNormalizedFilename])) {
            $this->fileSessionNormalizedFilename = (string)$diff[self::fileSessionNormalizedFilename];
        }
        if (array_key_exists(self::fileProgressFilename, $diff)) {
            $f = $diff[self::fileProgressFilename];
            $this->fileProgressFilename = is_string($f) && $f !== '' ? $f : null;
        }
        if (isset($diff[self::fileProgressUploadedBytes])) {
            $this->fileProgressUploadedBytes = (int)$diff[self::fileProgressUploadedBytes];
        }
        if (isset($diff[self::fileProgressTotalBytes])) {
            $this->fileProgressTotalBytes = (int)$diff[self::fileProgressTotalBytes];
        }
        if (isset($diff[self::uploadProgressLastSentAt])) {
            $this->uploadProgressLastSentAt = (float)$diff[self::uploadProgressLastSentAt];
        }
    }

    /**
     * @return string Runtime collection key (same as acceptKey)
     */
    public function getId(): string
    {
        return $this->acceptKey;
    }

    /**
     * @return array<string, mixed> Row for persistence / truth-source sync
     */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::userId => $this->userId,
            self::connectedAt => $this->connectedAt,
            self::outboundModerationRequestId => $this->outboundModerationRequestId,
            self::outboundModerationPhase => $this->outboundModerationPhase,
            self::outboundModerationMessage => $this->outboundModerationMessage,
            self::outboundModerationReason => $this->outboundModerationReason,
            self::outboundModerationUpdatedAt => $this->outboundModerationUpdatedAt,
            self::fileSessionUploadId => $this->fileSessionUploadId,
            self::fileSessionDeclaredSize => $this->fileSessionDeclaredSize,
            self::fileSessionReceivedBytes => $this->fileSessionReceivedBytes,
            self::fileSessionQuarantineBasename => $this->fileSessionQuarantineBasename,
            self::fileSessionOriginalFilename => $this->fileSessionOriginalFilename,
            self::fileSessionMimeType => $this->fileSessionMimeType,
            self::fileSessionClientUploadId => $this->fileSessionClientUploadId,
            self::fileSessionNormalizedFilename => $this->fileSessionNormalizedFilename,
            self::fileProgressFilename => $this->fileProgressFilename,
            self::fileProgressUploadedBytes => $this->fileProgressUploadedBytes,
            self::fileProgressTotalBytes => $this->fileProgressTotalBytes,
            self::uploadProgressLastSentAt => $this->uploadProgressLastSentAt,
        ];
    }
}
