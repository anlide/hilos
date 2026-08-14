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
     * @param array<string, mixed> $row Serialized runtime row (string keys match this class field constants)
     * @throws InvalidFormatException When the row is missing a field of this socket's chat state
     */
    protected function hydrateOwn(array $row): void
    {
        $this->connectedAt = self::requireInt($row, self::connectedAt);
        $this->outboundModerationPhase = self::requireString($row, self::outboundModerationPhase);
        $this->outboundModerationMessage = self::stringOrNull($row, self::outboundModerationMessage);
        $this->outboundModerationReason = self::nonEmptyStringOrNull($row, self::outboundModerationReason);
        $this->outboundModerationUpdatedAt = self::requireInt($row, self::outboundModerationUpdatedAt);
        $this->renameModerationPhase = self::requireString($row, self::renameModerationPhase);
        $this->renameModerationName = self::nonEmptyStringOrNull($row, self::renameModerationName);
        $this->renameModerationReason = self::nonEmptyStringOrNull($row, self::renameModerationReason);
        $this->renameModerationUpdatedAt = self::requireInt($row, self::renameModerationUpdatedAt);
        $this->fileSessionUploadId = self::nonEmptyStringOrNull($row, self::fileSessionUploadId);
        $this->fileSessionDeclaredSize = self::requireInt($row, self::fileSessionDeclaredSize);
        $this->fileSessionReceivedBytes = self::requireInt($row, self::fileSessionReceivedBytes);
        $this->fileSessionQuarantineBasename = self::nonEmptyStringOrNull($row, self::fileSessionQuarantineBasename);
        $this->fileSessionOriginalFilename = self::nonEmptyStringOrNull($row, self::fileSessionOriginalFilename);
        $this->fileSessionMimeType = self::nonEmptyStringOrNull($row, self::fileSessionMimeType);
        $this->fileSessionClientUploadId = self::nonEmptyStringOrNull($row, self::fileSessionClientUploadId);
        $this->fileSessionNormalizedFilename = self::nonEmptyStringOrNull($row, self::fileSessionNormalizedFilename);
        $this->fileUploadPhase = self::requireString($row, self::fileUploadPhase);
        $this->fileUploadClientUploadId = self::nonEmptyStringOrNull($row, self::fileUploadClientUploadId);
        $this->fileUploadErrorCode = self::nonEmptyStringOrNull($row, self::fileUploadErrorCode);
        $this->fileUploadErrorMessage = self::nonEmptyStringOrNull($row, self::fileUploadErrorMessage);
        $this->fileProgressFilename = self::nonEmptyStringOrNull($row, self::fileProgressFilename);
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
        if (array_key_exists(self::connectedAt, $diff)) {
            $this->connectedAt = self::requireInt($diff, self::connectedAt);
        }
        if (array_key_exists(self::outboundModerationPhase, $diff)) {
            $this->outboundModerationPhase = self::requireString($diff, self::outboundModerationPhase);
        }
        if (array_key_exists(self::outboundModerationMessage, $diff)) {
            $this->outboundModerationMessage = self::stringOrNull($diff, self::outboundModerationMessage);
        }
        if (array_key_exists(self::outboundModerationReason, $diff)) {
            $this->outboundModerationReason = self::nonEmptyStringOrNull($diff, self::outboundModerationReason);
        }
        if (array_key_exists(self::outboundModerationUpdatedAt, $diff)) {
            $this->outboundModerationUpdatedAt = self::requireInt($diff, self::outboundModerationUpdatedAt);
        }
        if (array_key_exists(self::renameModerationPhase, $diff)) {
            $this->renameModerationPhase = self::requireString($diff, self::renameModerationPhase);
        }
        if (array_key_exists(self::renameModerationName, $diff)) {
            $this->renameModerationName = self::nonEmptyStringOrNull($diff, self::renameModerationName);
        }
        if (array_key_exists(self::renameModerationReason, $diff)) {
            $this->renameModerationReason = self::nonEmptyStringOrNull($diff, self::renameModerationReason);
        }
        if (array_key_exists(self::renameModerationUpdatedAt, $diff)) {
            $this->renameModerationUpdatedAt = self::requireInt($diff, self::renameModerationUpdatedAt);
        }
        if (array_key_exists(self::fileSessionUploadId, $diff)) {
            $this->fileSessionUploadId = self::nonEmptyStringOrNull($diff, self::fileSessionUploadId);
        }
        if (array_key_exists(self::fileSessionDeclaredSize, $diff)) {
            $this->fileSessionDeclaredSize = self::requireInt($diff, self::fileSessionDeclaredSize);
        }
        if (array_key_exists(self::fileSessionReceivedBytes, $diff)) {
            $this->fileSessionReceivedBytes = self::requireInt($diff, self::fileSessionReceivedBytes);
        }
        if (array_key_exists(self::fileSessionQuarantineBasename, $diff)) {
            $this->fileSessionQuarantineBasename = self::nonEmptyStringOrNull(
                $diff,
                self::fileSessionQuarantineBasename,
            );
        }
        if (array_key_exists(self::fileSessionOriginalFilename, $diff)) {
            $this->fileSessionOriginalFilename = self::nonEmptyStringOrNull($diff, self::fileSessionOriginalFilename);
        }
        if (array_key_exists(self::fileSessionMimeType, $diff)) {
            $this->fileSessionMimeType = self::nonEmptyStringOrNull($diff, self::fileSessionMimeType);
        }
        if (array_key_exists(self::fileSessionClientUploadId, $diff)) {
            $this->fileSessionClientUploadId = self::nonEmptyStringOrNull($diff, self::fileSessionClientUploadId);
        }
        if (array_key_exists(self::fileSessionNormalizedFilename, $diff)) {
            $this->fileSessionNormalizedFilename = self::nonEmptyStringOrNull(
                $diff,
                self::fileSessionNormalizedFilename,
            );
        }
        if (array_key_exists(self::fileUploadPhase, $diff)) {
            $this->fileUploadPhase = self::requireString($diff, self::fileUploadPhase);
        }
        if (array_key_exists(self::fileUploadClientUploadId, $diff)) {
            $this->fileUploadClientUploadId = self::nonEmptyStringOrNull($diff, self::fileUploadClientUploadId);
        }
        if (array_key_exists(self::fileUploadErrorCode, $diff)) {
            $this->fileUploadErrorCode = self::nonEmptyStringOrNull($diff, self::fileUploadErrorCode);
        }
        if (array_key_exists(self::fileUploadErrorMessage, $diff)) {
            $this->fileUploadErrorMessage = self::nonEmptyStringOrNull($diff, self::fileUploadErrorMessage);
        }
        if (array_key_exists(self::fileProgressFilename, $diff)) {
            $this->fileProgressFilename = self::nonEmptyStringOrNull($diff, self::fileProgressFilename);
        }
        if (array_key_exists(self::fileProgressUploadedBytes, $diff)) {
            $this->fileProgressUploadedBytes = self::requireInt($diff, self::fileProgressUploadedBytes);
        }
        if (array_key_exists(self::fileProgressTotalBytes, $diff)) {
            $this->fileProgressTotalBytes = self::requireInt($diff, self::fileProgressTotalBytes);
        }
        if (array_key_exists(self::uploadProgressLastSentAt, $diff)) {
            $this->uploadProgressLastSentAt = self::requireFloat($diff, self::uploadProgressLastSentAt);
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
     * Reads one field of a runtime row or diff that the row cannot be built without.
     *
     * The row is written by {@see ownToArray()} on another worker, so a key that
     * is absent or holds another type is a row that lost the field on the way,
     * not a row that never had it. A cast would turn that loss into a phase this
     * socket is not in, and every reader below would act on it.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return string Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-string
     */
    private static function requireString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidFormatException('Runtime row carries no string under key ' . $key);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return int Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-integer
     */
    private static function requireInt(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidFormatException('Runtime row carries no integer under key ' . $key);
        }

        return $value;
    }

    /**
     * An integer is widened rather than refused: the row crosses the workers as
     * JSON, where `json_encode(0.0)` writes `0`, so a whole throttle stamp comes
     * back as an integer and refusing it would refuse the row this class wrote.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return float Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds neither a float nor an integer
     */
    private static function requireFloat(array $source, string $key): float
    {
        $value = $source[$key] ?? null;
        if (!is_float($value) && !is_int($value)) {
            throw new InvalidFormatException('Runtime row carries no number under key ' . $key);
        }

        return (float)$value;
    }

    /**
     * Reads one optional field of a runtime row or diff. A row written by a node
     * that has not been restarted yet still spells "no value" as the empty string
     * where this class now writes null, and the two say the same thing.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return ?string The value, or null when the field holds none
     * @throws InvalidFormatException When the key holds neither a string nor null
     */
    private static function nonEmptyStringOrNull(array $source, string $key): ?string
    {
        $value = self::stringOrNull($source, $key);

        return $value === '' ? null : $value;
    }

    /**
     * Reads one field whose empty string is the value itself, not a spelling of
     * absence. The submitted message is the only such field here: a message sent
     * with attachments and no text carries an empty text on purpose, and the
     * moderation state it starts must survive the trip to the other workers.
     * Absence is spelled by the phase field beside it, which such a row leaves
     * clear, so the two are never confused.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return ?string The value, or null when the field holds none
     * @throws InvalidFormatException When the key holds neither a string nor null
     */
    private static function stringOrNull(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new InvalidFormatException('Runtime row carries a non-string under key ' . $key);
        }

        return $value;
    }
}
