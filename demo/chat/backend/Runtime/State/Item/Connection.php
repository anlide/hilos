<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Runtime\State\Item\HilosConnection;

/**
 * Runtime row for one WebSocket connection (`acceptKey` is the collection id).
 *
 * Extends the framework {@see HilosConnection} base, which owns the session
 * triple (acceptKey / sessionToken / userId) and its hydrate/diff helpers; this
 * subclass adds chat's own in-memory moderation, file upload session, and
 * progress UI state for this socket only, delegating the triple to the base
 * {@see HilosConnection::initBase()} / {@see HilosConnection::hydrateBase()} /
 * {@see HilosConnection::baseToArray()} / {@see HilosConnection::applyBaseDiff()}.
 * Inbound RT updates use `applyDiff()`; local writes from item actions use typed
 * properties and `sync()`. Public string constants name row keys.
 */
final class Connection extends HilosConnection
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

    /** Moderation phase: checking, rejected, unavailable, or empty when clear. */
    public string $outboundModerationPhase = '';

    /**
     * Submitted message text associated with the moderation state — empty when
     * the submit carried attachments and no text — or null while none is.
     */
    public ?string $outboundModerationMessage = null;

    /** Moderation rejection or unavailable reason, or null while the verdict names none. */
    public ?string $outboundModerationReason = null;

    /** Unix time of last moderation state update. */
    public int $outboundModerationUpdatedAt = 0;

    /** Rename moderation phase: checking, rejected, unavailable, or empty when clear. */
    public string $renameModerationPhase = '';

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
        $instance->initBase($acceptKey, $userId, $sessionToken);
        $instance->connectedAt = time();
        $instance->outboundModerationPhase = '';
        $instance->outboundModerationMessage = null;
        $instance->outboundModerationReason = null;
        $instance->outboundModerationUpdatedAt = 0;
        $instance->renameModerationPhase = '';
        $instance->renameModerationName = null;
        $instance->renameModerationReason = null;
        $instance->renameModerationUpdatedAt = 0;
        $instance->fileSessionUploadId = null;
        $instance->fileSessionDeclaredSize = 0;
        $instance->fileSessionReceivedBytes = 0;
        $instance->fileSessionQuarantineBasename = null;
        $instance->fileSessionOriginalFilename = null;
        $instance->fileSessionMimeType = null;
        $instance->fileSessionClientUploadId = null;
        $instance->fileSessionNormalizedFilename = null;
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
        $instance->hydrateBase($row);
        $instance->connectedAt = (int)($row[self::connectedAt] ?? time());
        $instance->outboundModerationPhase = (string)($row[self::outboundModerationPhase] ?? '');
        $instance->outboundModerationMessage = self::stringOrNull($row[self::outboundModerationMessage] ?? null);
        $instance->outboundModerationReason = self::nonEmptyStringOrNull($row[self::outboundModerationReason] ?? null);
        $instance->outboundModerationUpdatedAt = (int)($row[self::outboundModerationUpdatedAt] ?? 0);
        $instance->renameModerationPhase = (string)($row[self::renameModerationPhase] ?? '');
        $instance->renameModerationName = self::nonEmptyStringOrNull($row[self::renameModerationName] ?? null);
        $instance->renameModerationReason = self::nonEmptyStringOrNull($row[self::renameModerationReason] ?? null);
        $instance->renameModerationUpdatedAt = (int)($row[self::renameModerationUpdatedAt] ?? 0);
        $instance->fileSessionUploadId = self::nonEmptyStringOrNull($row[self::fileSessionUploadId] ?? null);
        $instance->fileSessionDeclaredSize = (int)($row[self::fileSessionDeclaredSize] ?? 0);
        $instance->fileSessionReceivedBytes = (int)($row[self::fileSessionReceivedBytes] ?? 0);
        $instance->fileSessionQuarantineBasename = self::nonEmptyStringOrNull(
            $row[self::fileSessionQuarantineBasename] ?? null,
        );
        $instance->fileSessionOriginalFilename = self::nonEmptyStringOrNull(
            $row[self::fileSessionOriginalFilename] ?? null,
        );
        $instance->fileSessionMimeType = self::nonEmptyStringOrNull($row[self::fileSessionMimeType] ?? null);
        $instance->fileSessionClientUploadId = self::nonEmptyStringOrNull($row[self::fileSessionClientUploadId] ?? null);
        $instance->fileSessionNormalizedFilename = self::nonEmptyStringOrNull(
            $row[self::fileSessionNormalizedFilename] ?? null,
        );
        $instance->fileUploadPhase = (string)($row[self::fileUploadPhase] ?? '');
        $instance->fileUploadClientUploadId = self::nonEmptyStringOrNull($row[self::fileUploadClientUploadId] ?? null);
        $instance->fileUploadErrorCode = self::nonEmptyStringOrNull($row[self::fileUploadErrorCode] ?? null);
        $instance->fileUploadErrorMessage = self::nonEmptyStringOrNull($row[self::fileUploadErrorMessage] ?? null);
        $instance->fileProgressFilename = self::nonEmptyStringOrNull($row[self::fileProgressFilename] ?? null);
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
        $this->applyBaseDiff($diff);
        if (isset($diff[self::connectedAt])) {
            $this->connectedAt = (int)$diff[self::connectedAt];
        }
        if (isset($diff[self::outboundModerationPhase])) {
            $this->outboundModerationPhase = (string)$diff[self::outboundModerationPhase];
        }
        if (array_key_exists(self::outboundModerationMessage, $diff)) {
            $this->outboundModerationMessage = self::stringOrNull($diff[self::outboundModerationMessage]);
        }
        if (array_key_exists(self::outboundModerationReason, $diff)) {
            $this->outboundModerationReason = self::nonEmptyStringOrNull($diff[self::outboundModerationReason]);
        }
        if (isset($diff[self::outboundModerationUpdatedAt])) {
            $this->outboundModerationUpdatedAt = (int)$diff[self::outboundModerationUpdatedAt];
        }
        if (isset($diff[self::renameModerationPhase])) {
            $this->renameModerationPhase = (string)$diff[self::renameModerationPhase];
        }
        if (array_key_exists(self::renameModerationName, $diff)) {
            $this->renameModerationName = self::nonEmptyStringOrNull($diff[self::renameModerationName]);
        }
        if (array_key_exists(self::renameModerationReason, $diff)) {
            $this->renameModerationReason = self::nonEmptyStringOrNull($diff[self::renameModerationReason]);
        }
        if (isset($diff[self::renameModerationUpdatedAt])) {
            $this->renameModerationUpdatedAt = (int)$diff[self::renameModerationUpdatedAt];
        }
        if (array_key_exists(self::fileSessionUploadId, $diff)) {
            $this->fileSessionUploadId = self::nonEmptyStringOrNull($diff[self::fileSessionUploadId]);
        }
        if (isset($diff[self::fileSessionDeclaredSize])) {
            $this->fileSessionDeclaredSize = (int)$diff[self::fileSessionDeclaredSize];
        }
        if (isset($diff[self::fileSessionReceivedBytes])) {
            $this->fileSessionReceivedBytes = (int)$diff[self::fileSessionReceivedBytes];
        }
        if (array_key_exists(self::fileSessionQuarantineBasename, $diff)) {
            $this->fileSessionQuarantineBasename = self::nonEmptyStringOrNull(
                $diff[self::fileSessionQuarantineBasename],
            );
        }
        if (array_key_exists(self::fileSessionOriginalFilename, $diff)) {
            $this->fileSessionOriginalFilename = self::nonEmptyStringOrNull(
                $diff[self::fileSessionOriginalFilename],
            );
        }
        if (array_key_exists(self::fileSessionMimeType, $diff)) {
            $this->fileSessionMimeType = self::nonEmptyStringOrNull($diff[self::fileSessionMimeType]);
        }
        if (array_key_exists(self::fileSessionClientUploadId, $diff)) {
            $this->fileSessionClientUploadId = self::nonEmptyStringOrNull($diff[self::fileSessionClientUploadId]);
        }
        if (array_key_exists(self::fileSessionNormalizedFilename, $diff)) {
            $this->fileSessionNormalizedFilename = self::nonEmptyStringOrNull(
                $diff[self::fileSessionNormalizedFilename],
            );
        }
        if (isset($diff[self::fileUploadPhase])) {
            $this->fileUploadPhase = (string)$diff[self::fileUploadPhase];
        }
        if (array_key_exists(self::fileUploadClientUploadId, $diff)) {
            $this->fileUploadClientUploadId = self::nonEmptyStringOrNull($diff[self::fileUploadClientUploadId]);
        }
        if (array_key_exists(self::fileUploadErrorCode, $diff)) {
            $this->fileUploadErrorCode = self::nonEmptyStringOrNull($diff[self::fileUploadErrorCode]);
        }
        if (array_key_exists(self::fileUploadErrorMessage, $diff)) {
            $this->fileUploadErrorMessage = self::nonEmptyStringOrNull($diff[self::fileUploadErrorMessage]);
        }
        if (array_key_exists(self::fileProgressFilename, $diff)) {
            $this->fileProgressFilename = self::nonEmptyStringOrNull($diff[self::fileProgressFilename]);
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
     * @return array<string, mixed> Row for persistence / truth-source sync
     */
    public function toArray(): array
    {
        return array_merge($this->baseToArray(), [
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
        ]);
    }

    /**
     * Reads one optional field of a runtime row or diff. A row written by a node
     * that has not been restarted yet still spells "no value" as the empty string
     * where this class now writes null, and the two say the same thing.
     *
     * @param mixed $value Raw row or diff value
     * @return ?string The value, or null when the field holds none
     */
    private static function nonEmptyStringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Reads one field whose empty string is the value itself, not a spelling of
     * absence. The submitted message is the only such field here: a message sent
     * with attachments and no text carries an empty text on purpose, and the
     * moderation state it starts must survive the trip to the other workers.
     * Absence is spelled by the phase field beside it, which such a row leaves
     * clear, so the two are never confused.
     *
     * @param mixed $value Raw row or diff value
     * @return ?string The value, or null when the field holds no string at all
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
