<?php

declare(strict_types=1);

namespace Hilos\Files\Upload\DTO;

use Hilos\Auth\Code\DTO\CodeSendProgressSignalData;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\View\Item\HilosUpload;

/**
 * Uploads agent → the one connection: where one of its uploads stands (HIL-135).
 *
 * The frame carries the WHOLE state of the upload rather than the change, the shape
 * {@see CodeSendProgressSignalData} established: a frame that was missed costs nothing, the next
 * one says everything. A frame whose phase is null is legal and means the upload is gone -
 * canceled, replaced, expired or dropped with its agent - and every field but the id is null
 * then.
 */
final class UploadStateSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $clientUploadId Id the client gave the upload
     * @param ?string $phase Phase of the upload, or null when it is gone
     * @param ?int $receivedBytes Bytes received as of this frame, or null when it is gone
     * @param ?int $declaredSize Declared size in bytes, or null when it is gone
     * @param ?string $errorCode Code of the failure, on the failed phase alone
     * @param ?string $errorMessage Sentence of the failure, on the failed phase alone
     */
    public function __construct(
        public readonly string $clientUploadId,
        public readonly ?string $phase = null,
        public readonly ?int $receivedBytes = null,
        public readonly ?int $declaredSize = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    /**
     * @param HilosUpload $upload Upload to describe
     * @return self Frame carrying the whole state of the upload
     */
    public static function fromUpload(HilosUpload $upload): self
    {
        return new self(
            clientUploadId: $upload->clientUploadId,
            phase: $upload->phase->value,
            receivedBytes: $upload->receivedBytes,
            declaredSize: $upload->declaredSize,
            errorCode: $upload->errorCode,
            errorMessage: $upload->errorMessage,
        );
    }

    /**
     * @param string $clientUploadId Id of the upload that is gone
     * @return self Frame saying the upload is gone
     */
    public static function gone(string $clientUploadId): self
    {
        return new self(clientUploadId: $clientUploadId);
    }

    /**
     * @return array{clientUploadId: string, phase: ?string, receivedBytes: ?int, declaredSize: ?int,
     *     errorCode: ?string, errorMessage: ?string} Frame payload
     */
    public function toArray(): array
    {
        return [
            'clientUploadId' => $this->clientUploadId,
            'phase' => $this->phase,
            'receivedBytes' => $this->receivedBytes,
            'declaredSize' => $this->declaredSize,
            'errorCode' => $this->errorCode,
            'errorMessage' => $this->errorMessage,
        ];
    }

    /**
     * Rebuilds the state of one upload.
     *
     * Every field but the id is optional, and that is the gone frame rather than a lax reader.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the id is absent or a field is present and not of its type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            clientUploadId: self::requireString($data, 'clientUploadId'),
            phase: self::optionalString($data, 'phase'),
            receivedBytes: self::optionalInt($data, 'receivedBytes'),
            declaredSize: self::optionalInt($data, 'declaredSize'),
            errorCode: self::optionalString($data, 'errorCode'),
            errorMessage: self::optionalString($data, 'errorMessage'),
        );
    }
}
