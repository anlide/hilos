<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Context\RtChatContext;
use Hilos\Runtime\Exception\State\RtStatePropertyNotFoundException;
use Hilos\Runtime\Exception\State\RtStateReadOnlyException;
use Hilos\Runtime\State\Item\RtState;

/**
 * Runtime row for one WebSocket connection (`acceptKey` is the collection id).
 *
 * Holds transport metadata plus in-memory file upload session, progress UI, and file-moderation UI for this socket only.
 * Inbound RT updates use {@see applyDiff()}; local writes from item actions use magic assignment + {@see sync()}.
 * Public string constants name row keys.
 *
 * @property string $acceptKey WebSocket accept key (unique connection identifier)
 * @property int $userId User ID associated with this connection
 * @property int $connectedAt Unix timestamp when connection was established
 * @property ?string $fileSessionUploadId Active binary upload id or null
 * @property int $fileSessionDeclaredSize Declared total bytes for current upload session
 * @property int $fileSessionReceivedBytes Bytes received so far for current upload session
 * @property string $fileSessionQuarantineBasename Quarantine filename basename (.part)
 * @property string $fileSessionOriginalFilename Original client filename for current session
 * @property string $fileSessionMimeType MIME type for current session
 * @property string $fileSessionClientUploadId Opaque client-side upload correlation id
 * @property string $fileSessionNormalizedFilename Lowercase normalized basename for dedup checks
 * @property ?string $fileModPhase File moderation UI phase (moderating, rejected) or null
 * @property string $fileModFilename Filename shown in file moderation UI
 * @property int $fileModUploadedBytes Bytes shown in moderation UI
 * @property int $fileModTotalBytes Total bytes shown in moderation UI
 * @property string $fileModReason Rejection reason for moderation UI
 * @property int $fileModUpdatedAt Last moderation UI update unix time
 * @property ?string $fileProgressFilename Upload progress bar filename or null
 * @property int $fileProgressUploadedBytes Bytes uploaded for progress UI
 * @property int $fileProgressTotalBytes Total bytes for progress UI
 * @property float $uploadProgressLastSentAt Microtime of last upload-progress baseline to client (READY or progress_update)
 */
final class Connection extends RtState
{
    public const string acceptKey = 'acceptKey';
    public const string userId = 'userId';
    public const string connectedAt = 'connectedAt';

    public const string fileSessionUploadId = 'fileSessionUploadId';
    public const string fileSessionDeclaredSize = 'fileSessionDeclaredSize';
    public const string fileSessionReceivedBytes = 'fileSessionReceivedBytes';
    public const string fileSessionQuarantineBasename = 'fileSessionQuarantineBasename';
    public const string fileSessionOriginalFilename = 'fileSessionOriginalFilename';
    public const string fileSessionMimeType = 'fileSessionMimeType';
    public const string fileSessionClientUploadId = 'fileSessionClientUploadId';
    public const string fileSessionNormalizedFilename = 'fileSessionNormalizedFilename';

    public const string fileModPhase = 'fileModPhase';
    public const string fileModFilename = 'fileModFilename';
    public const string fileModUploadedBytes = 'fileModUploadedBytes';
    public const string fileModTotalBytes = 'fileModTotalBytes';
    public const string fileModReason = 'fileModReason';
    public const string fileModUpdatedAt = 'fileModUpdatedAt';

    public const string fileProgressFilename = 'fileProgressFilename';
    public const string fileProgressUploadedBytes = 'fileProgressUploadedBytes';
    public const string fileProgressTotalBytes = 'fileProgressTotalBytes';

    public const string uploadProgressLastSentAt = 'uploadProgressLastSentAt';

    /** @var string WebSocket accept key (primary id) */
    private string $acceptKey = '';

    /** @var int Owning database user id */
    private int $userId = 0;

    /** @var int Unix time when the socket was registered */
    private int $connectedAt = 0;

    /** @var ?string Active upload id or null */
    private ?string $fileSessionUploadId = null;

    /** @var int Declared total size for current upload */
    private int $fileSessionDeclaredSize = 0;

    /** @var int Bytes appended so far for current upload */
    private int $fileSessionReceivedBytes = 0;

    /** @var string Quarantine file basename */
    private string $fileSessionQuarantineBasename = '';

    /** @var string Client original filename for session */
    private string $fileSessionOriginalFilename = '';

    /** @var string MIME type for session */
    private string $fileSessionMimeType = '';

    /** @var string Client-side upload correlation id */
    private string $fileSessionClientUploadId = '';

    /** @var string Normalized basename for duplicate-name checks */
    private string $fileSessionNormalizedFilename = '';

    /** @var ?string File moderation banner phase or null */
    private ?string $fileModPhase = null;

    /** @var string Filename shown in file moderation UI */
    private string $fileModFilename = '';

    /** @var int Uploaded bytes shown in moderation UI */
    private int $fileModUploadedBytes = 0;

    /** @var int Total bytes shown in moderation UI */
    private int $fileModTotalBytes = 0;

    /** @var string Rejection reason text */
    private string $fileModReason = '';

    /** @var int Last moderation UI update unix time */
    private int $fileModUpdatedAt = 0;

    /** @var ?string Progress bar filename or null */
    private ?string $fileProgressFilename = null;

    /** @var int Bytes for upload progress UI */
    private int $fileProgressUploadedBytes = 0;

    /** @var int Total for upload progress UI */
    private int $fileProgressTotalBytes = 0;

    /** @var float Last FILE_UPLOAD_READY or FILE_UPLOAD_PROGRESS_UPDATE for throttle (microtime) */
    private float $uploadProgressLastSentAt = 0.0;

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
        $instance->fileSessionUploadId = null;
        $instance->fileSessionDeclaredSize = 0;
        $instance->fileSessionReceivedBytes = 0;
        $instance->fileSessionQuarantineBasename = '';
        $instance->fileSessionOriginalFilename = '';
        $instance->fileSessionMimeType = '';
        $instance->fileSessionClientUploadId = '';
        $instance->fileSessionNormalizedFilename = '';
        $instance->fileModPhase = null;
        $instance->fileModFilename = '';
        $instance->fileModUploadedBytes = 0;
        $instance->fileModTotalBytes = 0;
        $instance->fileModReason = '';
        $instance->fileModUpdatedAt = 0;
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
        $uid = $row[self::fileSessionUploadId] ?? null;
        $instance->fileSessionUploadId = is_string($uid) && $uid !== '' ? $uid : null;
        $instance->fileSessionDeclaredSize = (int)($row[self::fileSessionDeclaredSize] ?? 0);
        $instance->fileSessionReceivedBytes = (int)($row[self::fileSessionReceivedBytes] ?? 0);
        $instance->fileSessionQuarantineBasename = (string)($row[self::fileSessionQuarantineBasename] ?? '');
        $instance->fileSessionOriginalFilename = (string)($row[self::fileSessionOriginalFilename] ?? '');
        $instance->fileSessionMimeType = (string)($row[self::fileSessionMimeType] ?? '');
        $instance->fileSessionClientUploadId = (string)($row[self::fileSessionClientUploadId] ?? '');
        $instance->fileSessionNormalizedFilename = (string)($row[self::fileSessionNormalizedFilename] ?? '');
        $phase = $row[self::fileModPhase] ?? null;
        $instance->fileModPhase = is_string($phase) && $phase !== '' ? $phase : null;
        $instance->fileModFilename = (string)($row[self::fileModFilename] ?? '');
        $instance->fileModUploadedBytes = (int)($row[self::fileModUploadedBytes] ?? 0);
        $instance->fileModTotalBytes = (int)($row[self::fileModTotalBytes] ?? 0);
        $instance->fileModReason = (string)($row[self::fileModReason] ?? '');
        $instance->fileModUpdatedAt = (int)($row[self::fileModUpdatedAt] ?? 0);
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
     * Writable from {@see \Demo\Chat\Runtime\View\Actions\Item\ConnectionActions} (then call {@see sync()}).
     *
     * @throws RtStateReadOnlyException For unknown or immutable properties
     */
    public function __set(string $name, mixed $value): void
    {
        match ($name) {
            self::acceptKey => throw new RtStateReadOnlyException('acceptKey is immutable'),
            self::userId => $this->userId = (int)$value,
            self::connectedAt => $this->connectedAt = (int)$value,
            self::fileSessionUploadId => $this->fileSessionUploadId = is_string($value) && $value !== '' ? $value : null,
            self::fileSessionDeclaredSize => $this->fileSessionDeclaredSize = (int)$value,
            self::fileSessionReceivedBytes => $this->fileSessionReceivedBytes = (int)$value,
            self::fileSessionQuarantineBasename => $this->fileSessionQuarantineBasename = (string)$value,
            self::fileSessionOriginalFilename => $this->fileSessionOriginalFilename = (string)$value,
            self::fileSessionMimeType => $this->fileSessionMimeType = (string)$value,
            self::fileSessionClientUploadId => $this->fileSessionClientUploadId = (string)$value,
            self::fileSessionNormalizedFilename => $this->fileSessionNormalizedFilename = (string)$value,
            self::fileModPhase => $this->fileModPhase = is_string($value) && $value !== '' ? $value : null,
            self::fileModFilename => $this->fileModFilename = (string)$value,
            self::fileModUploadedBytes => $this->fileModUploadedBytes = (int)$value,
            self::fileModTotalBytes => $this->fileModTotalBytes = (int)$value,
            self::fileModReason => $this->fileModReason = (string)$value,
            self::fileModUpdatedAt => $this->fileModUpdatedAt = (int)$value,
            self::fileProgressFilename => $this->fileProgressFilename = is_string($value) && $value !== '' ? $value : null,
            self::fileProgressUploadedBytes => $this->fileProgressUploadedBytes = (int)$value,
            self::fileProgressTotalBytes => $this->fileProgressTotalBytes = (int)$value,
            self::uploadProgressLastSentAt => $this->uploadProgressLastSentAt = (float)$value,
            default => parent::__set($name, $value),
        };
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
        if (array_key_exists(self::fileModPhase, $diff)) {
            $p = $diff[self::fileModPhase];
            $this->fileModPhase = is_string($p) && $p !== '' ? $p : null;
        }
        if (isset($diff[self::fileModFilename])) {
            $this->fileModFilename = (string)$diff[self::fileModFilename];
        }
        if (isset($diff[self::fileModUploadedBytes])) {
            $this->fileModUploadedBytes = (int)$diff[self::fileModUploadedBytes];
        }
        if (isset($diff[self::fileModTotalBytes])) {
            $this->fileModTotalBytes = (int)$diff[self::fileModTotalBytes];
        }
        if (isset($diff[self::fileModReason])) {
            $this->fileModReason = (string)$diff[self::fileModReason];
        }
        if (isset($diff[self::fileModUpdatedAt])) {
            $this->fileModUpdatedAt = (int)$diff[self::fileModUpdatedAt];
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
     * Read-only access for code outside this class (private fields route through here).
     *
     * @throws RtStatePropertyNotFoundException When $name is not a declared field
     */
    public function __get(string $name): string|int|float|null
    {
        return match ($name) {
            self::acceptKey => $this->acceptKey,
            self::userId => $this->userId,
            self::connectedAt => $this->connectedAt,
            self::fileSessionUploadId => $this->fileSessionUploadId,
            self::fileSessionDeclaredSize => $this->fileSessionDeclaredSize,
            self::fileSessionReceivedBytes => $this->fileSessionReceivedBytes,
            self::fileSessionQuarantineBasename => $this->fileSessionQuarantineBasename,
            self::fileSessionOriginalFilename => $this->fileSessionOriginalFilename,
            self::fileSessionMimeType => $this->fileSessionMimeType,
            self::fileSessionClientUploadId => $this->fileSessionClientUploadId,
            self::fileSessionNormalizedFilename => $this->fileSessionNormalizedFilename,
            self::fileModPhase => $this->fileModPhase,
            self::fileModFilename => $this->fileModFilename,
            self::fileModUploadedBytes => $this->fileModUploadedBytes,
            self::fileModTotalBytes => $this->fileModTotalBytes,
            self::fileModReason => $this->fileModReason,
            self::fileModUpdatedAt => $this->fileModUpdatedAt,
            self::fileProgressFilename => $this->fileProgressFilename,
            self::fileProgressUploadedBytes => $this->fileProgressUploadedBytes,
            self::fileProgressTotalBytes => $this->fileProgressTotalBytes,
            self::uploadProgressLastSentAt => $this->uploadProgressLastSentAt,
            default => parent::__get($name),
        };
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
            self::fileSessionUploadId => $this->fileSessionUploadId,
            self::fileSessionDeclaredSize => $this->fileSessionDeclaredSize,
            self::fileSessionReceivedBytes => $this->fileSessionReceivedBytes,
            self::fileSessionQuarantineBasename => $this->fileSessionQuarantineBasename,
            self::fileSessionOriginalFilename => $this->fileSessionOriginalFilename,
            self::fileSessionMimeType => $this->fileSessionMimeType,
            self::fileSessionClientUploadId => $this->fileSessionClientUploadId,
            self::fileSessionNormalizedFilename => $this->fileSessionNormalizedFilename,
            self::fileModPhase => $this->fileModPhase,
            self::fileModFilename => $this->fileModFilename,
            self::fileModUploadedBytes => $this->fileModUploadedBytes,
            self::fileModTotalBytes => $this->fileModTotalBytes,
            self::fileModReason => $this->fileModReason,
            self::fileModUpdatedAt => $this->fileModUpdatedAt,
            self::fileProgressFilename => $this->fileProgressFilename,
            self::fileProgressUploadedBytes => $this->fileProgressUploadedBytes,
            self::fileProgressTotalBytes => $this->fileProgressTotalBytes,
            self::uploadProgressLastSentAt => $this->uploadProgressLastSentAt,
        ];
    }
}
