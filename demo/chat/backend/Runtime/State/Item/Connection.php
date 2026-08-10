<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Item;

use Demo\Chat\Runtime\View\Context\ChatRtContext;
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
     * Stamps the moment the socket was registered; every other chat field opens
     * on the value its declaration already carries.
     */
    protected function initOwn(): void
    {
        $this->connectedAt = time();
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (string keys match this class field constants)
     */
    protected function hydrateOwn(array $row): void
    {
        $this->connectedAt = (int)($row[self::connectedAt] ?? time());
        $this->outboundModerationPhase = (string)($row[self::outboundModerationPhase] ?? '');
        $this->outboundModerationMessage = self::stringOrNull($row[self::outboundModerationMessage] ?? null);
        $this->outboundModerationReason = self::nonEmptyStringOrNull($row[self::outboundModerationReason] ?? null);
        $this->outboundModerationUpdatedAt = (int)($row[self::outboundModerationUpdatedAt] ?? 0);
        $this->renameModerationPhase = (string)($row[self::renameModerationPhase] ?? '');
        $this->renameModerationName = self::nonEmptyStringOrNull($row[self::renameModerationName] ?? null);
        $this->renameModerationReason = self::nonEmptyStringOrNull($row[self::renameModerationReason] ?? null);
        $this->renameModerationUpdatedAt = (int)($row[self::renameModerationUpdatedAt] ?? 0);
        $this->fileSessionUploadId = self::nonEmptyStringOrNull($row[self::fileSessionUploadId] ?? null);
        $this->fileSessionDeclaredSize = (int)($row[self::fileSessionDeclaredSize] ?? 0);
        $this->fileSessionReceivedBytes = (int)($row[self::fileSessionReceivedBytes] ?? 0);
        $this->fileSessionQuarantineBasename = self::nonEmptyStringOrNull(
            $row[self::fileSessionQuarantineBasename] ?? null,
        );
        $this->fileSessionOriginalFilename = self::nonEmptyStringOrNull(
            $row[self::fileSessionOriginalFilename] ?? null,
        );
        $this->fileSessionMimeType = self::nonEmptyStringOrNull($row[self::fileSessionMimeType] ?? null);
        $this->fileSessionClientUploadId = self::nonEmptyStringOrNull($row[self::fileSessionClientUploadId] ?? null);
        $this->fileSessionNormalizedFilename = self::nonEmptyStringOrNull(
            $row[self::fileSessionNormalizedFilename] ?? null,
        );
        $this->fileUploadPhase = (string)($row[self::fileUploadPhase] ?? '');
        $this->fileUploadClientUploadId = self::nonEmptyStringOrNull($row[self::fileUploadClientUploadId] ?? null);
        $this->fileUploadErrorCode = self::nonEmptyStringOrNull($row[self::fileUploadErrorCode] ?? null);
        $this->fileUploadErrorMessage = self::nonEmptyStringOrNull($row[self::fileUploadErrorMessage] ?? null);
        $this->fileProgressFilename = self::nonEmptyStringOrNull($row[self::fileProgressFilename] ?? null);
        $this->fileProgressUploadedBytes = (int)($row[self::fileProgressUploadedBytes] ?? 0);
        $this->fileProgressTotalBytes = (int)($row[self::fileProgressTotalBytes] ?? 0);
        $this->uploadProgressLastSentAt = (float)($row[self::uploadProgressLastSentAt] ?? 0.0);
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
    protected function applyOwnDiff(array $diff): void
    {
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
