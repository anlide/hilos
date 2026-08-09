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
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        $clientId = $data['clientUploadId'] ?? null;

        return new static(
            filename: self::requireString($data, 'filename'),
            mimeType: self::requireString($data, 'mimeType'),
            size: is_int($data['size'] ?? null) ? $data['size'] : (int)($data['size'] ?? 0),
            clientUploadId: is_string($clientId) && $clientId !== '' ? $clientId : null,
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
