<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Files\Upload\UploadFrame;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Runtime\State\Collection\HilosUploads;

/**
 * HilosUpload - one file a connection is sending, or has sent and not yet handed over (HIL-135).
 *
 * The upload lives on the CONNECTION, not on the page that started it: the row is keyed by the
 * accept key of the connection and the id the client gave the upload, and a navigation inside
 * the application does not touch it. Its temporary file sits in the tmp directory under
 * {@see self::$tmpIndex} until a consumer takes it or the row goes.
 *
 * Framework-owned runtime state mounted by the uploads feature ({@see HilosUploads}) and written
 * by {@see UploadsAgent} and nobody else. The row is written on a change of phase and at most
 * once per throttle interval while chunks arrive - never per chunk: the collection is held in
 * every process, and every write is a sync to all of them. The exact byte count between writes
 * lives in the agent's memory.
 */
final class HilosUpload extends RtState
{
    /** Runtime collection key mounted by the uploads feature and used for RT sync. */
    public const string RT_COLLECTION = 'hilosUploads';

    /** Separator of the two halves of the row id; neither an accept key nor an upload id contains it. */
    private const string KEY_SEPARATOR = '|';

    public const string acceptKey = 'acceptKey';
    public const string clientUploadId = 'clientUploadId';
    public const string target = 'target';
    public const string userId = 'userId';
    public const string filename = 'filename';
    public const string mimeType = 'mimeType';
    public const string declaredSize = 'declaredSize';
    public const string receivedBytes = 'receivedBytes';
    public const string tmpIndex = 'tmpIndex';
    public const string phase = 'phase';
    public const string detectedMimeType = 'detectedMimeType';
    public const string errorCode = 'errorCode';
    public const string errorMessage = 'errorMessage';
    public const string updatedAt = 'updatedAt';

    /** Accept key of the connection the upload belongs to. */
    private(set) string $acceptKey = '';

    /** Id the client gave the upload, unique on its connection ({@see UploadFrame::isValidId()}). */
    private(set) string $clientUploadId = '';

    /** Name of the upload target in the project's UPLOAD_TARGETS. */
    private(set) string $target = '';

    /** User signed in on the connection when the file was declared, or null for a guest. */
    private(set) ?int $userId = null;

    /** File name without any path. */
    private(set) string $filename = '';

    /** Declared type, normalized. */
    private(set) string $mimeType = '';

    /** Declared size in bytes. */
    private(set) int $declaredSize = 0;

    /** Bytes received as of the last write of the row; the exact count lives in the agent. */
    private(set) int $receivedBytes = 0;

    /** Index of the temporary file in the tmp directory, or null once the file is deleted. */
    private(set) ?string $tmpIndex = null;

    /** Raw {@see UploadPhase} value. */
    private(set) string $phase = '';

    /** Type read from the content of the whole file, only for a target that sniffs. */
    private(set) ?string $detectedMimeType = null;

    /** Code of the failure, on the failed phase alone. */
    private(set) ?string $errorCode = null;

    /** Sentence of the failure, on the failed phase alone. */
    private(set) ?string $errorMessage = null;

    /** Unix seconds of the last change of the row. */
    private(set) int $updatedAt = 0;

    /**
     * Composes the row id from the connection and the id the client gave the upload.
     *
     * @param string $acceptKey Accept key of the connection
     * @param string $clientUploadId Id the client gave the upload
     * @return string Runtime row id `acceptKey|clientUploadId`
     */
    public static function keyFor(string $acceptKey, string $clientUploadId): string
    {
        return $acceptKey . self::KEY_SEPARATOR . $clientUploadId;
    }

    /**
     * Opens an upload the agent has just accepted, with no byte received.
     *
     * @param string $acceptKey Accept key of the connection
     * @param string $clientUploadId Id the client gave the upload
     * @param string $target Name of the upload target
     * @param ?int $userId User signed in on the connection, or null for a guest
     * @param string $filename File name without any path
     * @param string $mimeType Declared type, normalized
     * @param int $declaredSize Declared size in bytes
     * @param string $tmpIndex Index of the empty temporary file created for it
     * @param int $updatedAt Unix seconds of this write
     * @return static Fresh upload row in the ready phase
     */
    public static function create(
        string $acceptKey,
        string $clientUploadId,
        string $target,
        ?int $userId,
        string $filename,
        string $mimeType,
        int $declaredSize,
        string $tmpIndex,
        int $updatedAt,
    ): static {
        $instance = new static();
        $instance->acceptKey = $acceptKey;
        $instance->clientUploadId = $clientUploadId;
        $instance->target = $target;
        $instance->userId = $userId;
        $instance->filename = $filename;
        $instance->mimeType = $mimeType;
        $instance->declaredSize = $declaredSize;
        $instance->tmpIndex = $tmpIndex;
        $instance->phase = UploadPhase::READY->value;
        $instance->updatedAt = $updatedAt;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Upload row restored from a sync row
     * @throws InvalidFormatException When the row lost a field the upload is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->acceptKey = self::requireString($row, self::acceptKey);
        $instance->clientUploadId = self::requireString($row, self::clientUploadId);
        $instance->target = self::requireString($row, self::target);
        $instance->userId = self::optionalInt($row, self::userId);
        $instance->filename = self::requireString($row, self::filename);
        $instance->mimeType = self::requireString($row, self::mimeType);
        $instance->declaredSize = self::requireInt($row, self::declaredSize);
        $instance->receivedBytes = self::requireInt($row, self::receivedBytes);
        $instance->tmpIndex = self::optionalString($row, self::tmpIndex);
        $instance->phase = self::readPhase(self::requireString($row, self::phase));
        $instance->detectedMimeType = self::optionalString($row, self::detectedMimeType);
        $instance->errorCode = self::optionalString($row, self::errorCode);
        $instance->errorMessage = self::optionalString($row, self::errorMessage);
        $instance->updatedAt = self::requireInt($row, self::updatedAt);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this row.
     *
     * Only what arrives and how it ends moves. The connection, the id, the target, the person
     * and what was declared are what the upload IS; a row that could change them would let
     * chunks addressed to one upload land in another.
     *
     * @param array<string, mixed> $diff Changed fields and values from another process
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->receivedBytes = self::patchInt($diff, self::receivedBytes, $this->receivedBytes);
        $this->tmpIndex = self::patchOptionalString($diff, self::tmpIndex, $this->tmpIndex);
        $this->phase = self::readPhase(self::patchString($diff, self::phase, $this->phase));
        $this->detectedMimeType = self::patchOptionalString($diff, self::detectedMimeType, $this->detectedMimeType);
        $this->errorCode = self::patchOptionalString($diff, self::errorCode, $this->errorCode);
        $this->errorMessage = self::patchOptionalString($diff, self::errorMessage, $this->errorMessage);
        $this->updatedAt = self::patchInt($diff, self::updatedAt, $this->updatedAt);
    }

    /**
     * @return string Runtime collection key for uploads
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @return string Runtime row id `acceptKey|clientUploadId`
     */
    public function getId(): string
    {
        return self::keyFor($this->acceptKey, $this->clientUploadId);
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::clientUploadId => $this->clientUploadId,
            self::target => $this->target,
            self::userId => $this->userId,
            self::filename => $this->filename,
            self::mimeType => $this->mimeType,
            self::declaredSize => $this->declaredSize,
            self::receivedBytes => $this->receivedBytes,
            self::tmpIndex => $this->tmpIndex,
            self::phase => $this->phase,
            self::detectedMimeType => $this->detectedMimeType,
            self::errorCode => $this->errorCode,
            self::errorMessage => $this->errorMessage,
            self::updatedAt => $this->updatedAt,
        ];
    }

    /**
     * Refuses a phase that is not one of {@see UploadPhase}, so the view can hand it out as the enum.
     *
     * @param string $phase Raw phase as it arrived
     * @return string The same phase
     * @throws InvalidFormatException When the value is not an upload phase
     */
    private static function readPhase(string $phase): string
    {
        if (UploadPhase::tryFrom($phase) === null) {
            throw new InvalidFormatException("Invalid upload phase: {$phase}");
        }

        return $phase;
    }
}
