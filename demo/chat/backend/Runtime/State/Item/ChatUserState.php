<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Hilos\Runtime\Exception\State\RtStatePropertyNotFoundException;
use Hilos\Runtime\State\Item\RtState;

/**
 * ChatUserState - Per-user chat runtime: text moderation, file upload session, file UI, progress.
 *
 * ID is userId as string. Always present for each DB user after seed/ensure.
 */
final class ChatUserState extends RtState
{
    public const string userId = 'userId';
    public const string moderationMessage = 'moderationMessage';
    public const string moderationUpdatedAt = 'moderationUpdatedAt';

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

    private int $userId {
        get => $this->userId;
    }

    private string $moderationMessage {
        get => $this->moderationMessage;
    }

    private int $moderationUpdatedAt {
        get => $this->moderationUpdatedAt;
    }

    private ?string $fileSessionUploadId {
        get => $this->fileSessionUploadId;
    }

    private int $fileSessionDeclaredSize {
        get => $this->fileSessionDeclaredSize;
    }

    private int $fileSessionReceivedBytes {
        get => $this->fileSessionReceivedBytes;
    }

    private string $fileSessionQuarantineBasename {
        get => $this->fileSessionQuarantineBasename;
    }

    private string $fileSessionOriginalFilename {
        get => $this->fileSessionOriginalFilename;
    }

    private string $fileSessionMimeType {
        get => $this->fileSessionMimeType;
    }

    private string $fileSessionClientUploadId {
        get => $this->fileSessionClientUploadId;
    }

    private string $fileSessionNormalizedFilename {
        get => $this->fileSessionNormalizedFilename;
    }

    private ?string $fileModPhase {
        get => $this->fileModPhase;
    }

    private string $fileModFilename {
        get => $this->fileModFilename;
    }

    private int $fileModUploadedBytes {
        get => $this->fileModUploadedBytes;
    }

    private int $fileModTotalBytes {
        get => $this->fileModTotalBytes;
    }

    private string $fileModReason {
        get => $this->fileModReason;
    }

    private int $fileModUpdatedAt {
        get => $this->fileModUpdatedAt;
    }

    private ?string $fileProgressFilename {
        get => $this->fileProgressFilename;
    }

    private int $fileProgressUploadedBytes {
        get => $this->fileProgressUploadedBytes;
    }

    private int $fileProgressTotalBytes {
        get => $this->fileProgressTotalBytes;
    }

    private float $uploadProgressLastSentAt {
        get => $this->uploadProgressLastSentAt;
    }

    public static function createEmpty(int $userId): static
    {
        $instance = new static();
        $instance->userId = $userId;
        $instance->moderationMessage = '';
        $instance->moderationUpdatedAt = 0;
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

        return $instance;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->userId = (int)($row[self::userId] ?? 0);
        $instance->moderationMessage = (string)($row[self::moderationMessage] ?? '');
        $instance->moderationUpdatedAt = (int)($row[self::moderationUpdatedAt] ?? 0);
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

        return $instance;
    }

    /**
     * @param array<string, mixed> $diff
     */
    public function applyDiff(array $diff): void
    {
        if (isset($diff[self::moderationMessage])) {
            $this->moderationMessage = (string)$diff[self::moderationMessage];
        }
        if (isset($diff[self::moderationUpdatedAt])) {
            $this->moderationUpdatedAt = (int)$diff[self::moderationUpdatedAt];
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

    public function getId(): string
    {
        return (string)$this->userId;
    }

    public function __get(string $name): int|string|float|null
    {
        return match ($name) {
            self::userId => $this->userId,
            self::moderationMessage => $this->moderationMessage,
            self::moderationUpdatedAt => $this->moderationUpdatedAt,
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::moderationMessage => $this->moderationMessage,
            self::moderationUpdatedAt => $this->moderationUpdatedAt,
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

    public function hasActiveFileUploadSession(): bool
    {
        return $this->fileSessionUploadId !== null;
    }

    /**
     * @return ?array<string, mixed>
     */
    public function getFileModerationUiPayload(): ?array
    {
        if ($this->fileModPhase === null) {
            return null;
        }

        return [
            'phase' => $this->fileModPhase,
            'filename' => $this->fileModFilename,
            'uploadedBytes' => $this->fileModUploadedBytes,
            'totalBytes' => $this->fileModTotalBytes,
            'reason' => $this->fileModReason !== '' ? $this->fileModReason : null,
            'updatedAt' => $this->fileModUpdatedAt,
        ];
    }

    /**
     * @return ?array<string, mixed>
     */
    public function getFileUploadProgressPayload(): ?array
    {
        if ($this->fileProgressFilename === null) {
            return null;
        }

        return [
            'filename' => $this->fileProgressFilename,
            'uploadedBytes' => $this->fileProgressUploadedBytes,
            'totalBytes' => $this->fileProgressTotalBytes,
        ];
    }

    /**
     * @return ?array<string, mixed> Session map for handlers or null
     */
    public function getFileUploadSessionArray(): ?array
    {
        if ($this->fileSessionUploadId === null) {
            return null;
        }

        return [
            'uploadId' => $this->fileSessionUploadId,
            'declaredSize' => $this->fileSessionDeclaredSize,
            'receivedBytes' => $this->fileSessionReceivedBytes,
            'quarantineBasename' => $this->fileSessionQuarantineBasename,
            'originalFilename' => $this->fileSessionOriginalFilename,
            'mimeType' => $this->fileSessionMimeType,
            'clientUploadId' => $this->fileSessionClientUploadId,
            'normalizedFilename' => $this->fileSessionNormalizedFilename,
        ];
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed> Diff for applyDiffToState
     */
    public static function diffForFileSession(array $session): array
    {
        return [
            self::fileSessionUploadId => $session['uploadId'],
            self::fileSessionDeclaredSize => (int)$session['declaredSize'],
            self::fileSessionReceivedBytes => (int)$session['receivedBytes'],
            self::fileSessionQuarantineBasename => (string)$session['quarantineBasename'],
            self::fileSessionOriginalFilename => (string)$session['originalFilename'],
            self::fileSessionMimeType => (string)$session['mimeType'],
            self::fileSessionClientUploadId => (string)$session['clientUploadId'],
            self::fileSessionNormalizedFilename => (string)$session['normalizedFilename'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function diffClearFileSession(): array
    {
        return [
            self::fileSessionUploadId => null,
            self::fileSessionDeclaredSize => 0,
            self::fileSessionReceivedBytes => 0,
            self::fileSessionQuarantineBasename => '',
            self::fileSessionOriginalFilename => '',
            self::fileSessionMimeType => '',
            self::fileSessionClientUploadId => '',
            self::fileSessionNormalizedFilename => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function diffClearFileModerationUi(): array
    {
        return [
            self::fileModPhase => null,
            self::fileModFilename => '',
            self::fileModUploadedBytes => 0,
            self::fileModTotalBytes => 0,
            self::fileModReason => '',
            self::fileModUpdatedAt => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function diffClearFileProgress(): array
    {
        return [
            self::fileProgressFilename => null,
            self::fileProgressUploadedBytes => 0,
            self::fileProgressTotalBytes => 0,
            self::uploadProgressLastSentAt => 0.0,
        ];
    }
}
