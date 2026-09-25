<?php

declare(strict_types=1);

namespace Hilos\Files\Upload\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Files\Upload\UploadFrame;

/**
 * UploadCancelActionDTO - a browser drops one of its uploads, in any phase (HIL-135).
 */
final class UploadCancelActionDTO extends ActionPayloadDTO
{
    /**
     * @param string $clientUploadId Id the client gave the upload
     */
    public function __construct(
        public readonly string $clientUploadId,
    ) {
    }

    /**
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_UPLOAD_CANCEL;
    }

    /**
     * @param array<string, mixed> $data Payload data
     * @return static Cancel of one upload
     * @throws InvalidFormatException When the upload id is absent, not a string, or not one a chunk can carry
     */
    public static function fromArray(array $data): static
    {
        $clientUploadId = self::requireString($data, 'clientUploadId');
        if (!UploadFrame::isValidId($clientUploadId)) {
            throw new InvalidFormatException(
                'Invalid clientUploadId. Expected 1 to ' . UploadFrame::MAX_ID_LENGTH . ' characters of A-Z, a-z, 0-9, _ and -.'
            );
        }

        return new static(clientUploadId: $clientUploadId);
    }

    /**
     * @return array{clientUploadId: string} Cancel payload
     */
    public function toArray(): array
    {
        return [
            'clientUploadId' => $this->clientUploadId,
        ];
    }
}
