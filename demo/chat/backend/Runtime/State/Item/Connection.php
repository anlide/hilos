<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Actions\Item\ConnectionActions;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Runtime\State\Item\RtState;

/**
 * Runtime row for one WebSocket connection (`acceptKey` is the collection id).
 *
 * Holds transport metadata plus in-memory moderation, file upload session, and progress UI for this socket only.
 * Inbound RT updates use `applyDiff()`; local writes from item actions use typed properties and `sync()`.
 * Public string constants name row keys.
 */
final class Connection extends RtState
{
    public const string acceptKey = 'acceptKey';
    public const string sessionToken = 'sessionToken';
    public const string userId = 'userId';
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

    /** WebSocket accept key (primary id). */
    private(set) string $acceptKey = '';

    /** Session cookie token this connection belongs to. */
    private(set) string $sessionToken = '';

    /** Authenticated database user id, or null while the session is anonymous. */
    public ?int $userId = null;

    /** Unix time when the socket was registered. */
    private(set) int $connectedAt = 0;

    /** Moderation phase: checking, rejected, unavailable, or empty when clear. */
    public string $outboundModerationPhase = '';

    /** Submitted message text associated with the moderation state. */
    public string $outboundModerationMessage = '';

    /** Moderation rejection or unavailable reason. */
    public string $outboundModerationReason = '';

    /** Unix time of last moderation state update. */
    public int $outboundModerationUpdatedAt = 0;

    /** Rename moderation phase: checking, rejected, unavailable, or empty when clear. */
    public string $renameModerationPhase = '';

    /** Requested display name associated with rename moderation. */
    public string $renameModerationName = '';

    /** Rename moderation rejection or unavailable reason. */
    public string $renameModerationReason = '';

    /** Unix time of last rename moderation state update. */
    public int $renameModerationUpdatedAt = 0;

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

    /** Upload UI phase: ready, uploading, failed, or empty when idle. */
    public string $fileUploadPhase = '';

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
     * @param string $acceptKey WebSocket accept key (unique identifier)
     * @param ?int $userId Authenticated user id, or null for an anonymous session
     * @param string $sessionToken Session cookie token this connection belongs to
     */
    public static function create(string $acceptKey, ?int $userId, string $sessionToken = ''): static
    {
        $instance = new static();
        $instance->acceptKey = $acceptKey;
        $instance->sessionToken = $sessionToken;
        $instance->userId = $userId;
        $instance->connectedAt = time();
        $instance->outboundModerationPhase = '';
        $instance->outboundModerationMessage = '';
        $instance->outboundModerationReason = '';
        $instance->outboundModerationUpdatedAt = 0;
        $instance->renameModerationPhase = '';
        $instance->renameModerationName = '';
        $instance->renameModerationReason = '';
        $instance->renameModerationUpdatedAt = 0;
        $instance->fileSessionUploadId = null;
        $instance->fileSessionDeclaredSize = 0;
        $instance->fileSessionReceivedBytes = 0;
        $instance->fileSessionQuarantineBasename = '';
        $instance->fileSessionOriginalFilename = '';
        $instance->fileSessionMimeType = '';
        $instance->fileSessionClientUploadId = '';
        $instance->fileSessionNormalizedFilename = '';
        $instance->fileUploadPhase = '';
        $instance->fileUploadClientUploadId = null;
        $instance->fileUploadErrorCode = null;
        $instance->fileUploadErrorMessage = null;
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
        $instance->sessionToken = (string)($row[self::sessionToken] ?? '');
        $instance->userId = isset($row[self::userId]) ? (int)$row[self::userId] : null;
        $instance->connectedAt = (int)($row[self::connectedAt] ?? time());
        $instance->outboundModerationPhase = (string)($row[self::outboundModerationPhase] ?? '');
        $instance->outboundModerationMessage = (string)($row[self::outboundModerationMessage] ?? '');
        $instance->outboundModerationReason = (string)($row[self::outboundModerationReason] ?? '');
        $instance->outboundModerationUpdatedAt = (int)($row[self::outboundModerationUpdatedAt] ?? 0);
        $instance->renameModerationPhase = (string)($row[self::renameModerationPhase] ?? '');
        $instance->renameModerationName = (string)($row[self::renameModerationName] ?? '');
        $instance->renameModerationReason = (string)($row[self::renameModerationReason] ?? '');
        $instance->renameModerationUpdatedAt = (int)($row[self::renameModerationUpdatedAt] ?? 0);
        $uid = $row[self::fileSessionUploadId] ?? null;
        $instance->fileSessionUploadId = is_string($uid) && $uid !== '' ? $uid : null;
        $instance->fileSessionDeclaredSize = (int)($row[self::fileSessionDeclaredSize] ?? 0);
        $instance->fileSessionReceivedBytes = (int)($row[self::fileSessionReceivedBytes] ?? 0);
        $instance->fileSessionQuarantineBasename = (string)($row[self::fileSessionQuarantineBasename] ?? '');
        $instance->fileSessionOriginalFilename = (string)($row[self::fileSessionOriginalFilename] ?? '');
        $instance->fileSessionMimeType = (string)($row[self::fileSessionMimeType] ?? '');
        $instance->fileSessionClientUploadId = (string)($row[self::fileSessionClientUploadId] ?? '');
        $instance->fileSessionNormalizedFilename = (string)($row[self::fileSessionNormalizedFilename] ?? '');
        $instance->fileUploadPhase = (string)($row[self::fileUploadPhase] ?? '');
        $uploadClientId = $row[self::fileUploadClientUploadId] ?? null;
        $instance->fileUploadClientUploadId = is_string($uploadClientId) && $uploadClientId !== ''
            ? $uploadClientId
            : null;
        $errorCode = $row[self::fileUploadErrorCode] ?? null;
        $instance->fileUploadErrorCode = is_string($errorCode) && $errorCode !== '' ? $errorCode : null;
        $errorMessage = $row[self::fileUploadErrorMessage] ?? null;
        $instance->fileUploadErrorMessage = is_string($errorMessage) && $errorMessage !== ''
            ? $errorMessage
            : null;
        $pfn = $row[self::fileProgressFilename] ?? null;
        $instance->fileProgressFilename = is_string($pfn) && $pfn !== '' ? $pfn : null;
        $instance->fileProgressUploadedBytes = (int)($row[self::fileProgressUploadedBytes] ?? 0);
        $instance->fileProgressTotalBytes = (int)($row[self::fileProgressTotalBytes] ?? 0);
        $instance->uploadProgressLastSentAt = (float)($row[self::uploadProgressLastSentAt] ?? 0.0);
        $instance->markRtSyncBaseline();

        return $instance;
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
     * @param array<string, mixed> $diff Partial update; keys are public `* = 'fieldName'` constants on this class
     */
    public function applyDiff(array $diff): void
    {
        if (array_key_exists(self::sessionToken, $diff)) {
            $this->sessionToken = (string)$diff[self::sessionToken];
        }
        if (array_key_exists(self::userId, $diff)) {
            $this->userId = $diff[self::userId] === null ? null : (int)$diff[self::userId];
        }
        if (isset($diff[self::connectedAt])) {
            $this->connectedAt = (int)$diff[self::connectedAt];
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
        if (isset($diff[self::renameModerationPhase])) {
            $this->renameModerationPhase = (string)$diff[self::renameModerationPhase];
        }
        if (isset($diff[self::renameModerationName])) {
            $this->renameModerationName = (string)$diff[self::renameModerationName];
        }
        if (isset($diff[self::renameModerationReason])) {
            $this->renameModerationReason = (string)$diff[self::renameModerationReason];
        }
        if (isset($diff[self::renameModerationUpdatedAt])) {
            $this->renameModerationUpdatedAt = (int)$diff[self::renameModerationUpdatedAt];
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
        if (isset($diff[self::fileUploadPhase])) {
            $this->fileUploadPhase = (string)$diff[self::fileUploadPhase];
        }
        if (array_key_exists(self::fileUploadClientUploadId, $diff)) {
            $v = $diff[self::fileUploadClientUploadId];
            $this->fileUploadClientUploadId = is_string($v) && $v !== '' ? $v : null;
        }
        if (array_key_exists(self::fileUploadErrorCode, $diff)) {
            $v = $diff[self::fileUploadErrorCode];
            $this->fileUploadErrorCode = is_string($v) && $v !== '' ? $v : null;
        }
        if (array_key_exists(self::fileUploadErrorMessage, $diff)) {
            $v = $diff[self::fileUploadErrorMessage];
            $this->fileUploadErrorMessage = is_string($v) && $v !== '' ? $v : null;
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
            self::sessionToken => $this->sessionToken,
            self::userId => $this->userId,
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
