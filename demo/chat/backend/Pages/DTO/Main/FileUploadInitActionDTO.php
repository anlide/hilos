<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * FileUploadInitActionDTO - Start a binary WebSocket file upload.
 */
final class FileUploadInitActionDTO extends ChatActionPayloadDTO
{
    public function __construct(
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly ?string $clientUploadId = null,
    ) {
    }

    public function getAction(): string
    {
        return ChatSignalConstants::FILE_UPLOAD_INIT;
    }

    /**
     * @param array<string, mixed> $data Payload data
     * @return static Upload init DTO instance
     * @throws InvalidFormatException When a field the upload is announced by is absent or of another type
     */
    public static function fromArray(array $data): static
    {
        $clientUploadId = self::optionalString($data, 'clientUploadId');

        return new static(
            filename: self::requireString($data, 'filename'),
            mimeType: self::requireString($data, 'mimeType'),
            size: self::requireInt($data, 'size'),
            clientUploadId: $clientUploadId !== '' ? $clientUploadId : null,
        );
    }

    /**
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'mimeType' => $this->mimeType,
            'size' => $this->size,
            'clientUploadId' => $this->clientUploadId,
        ];
    }

    public function isValid(): bool
    {
        return $this->filename !== ''
            && $this->size > 0
            && $this->mimeType !== ''
            && $this->clientUploadId !== null;
    }
}
