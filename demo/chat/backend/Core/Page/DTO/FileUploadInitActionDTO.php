<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Page\DTO;

use Demo\Chat\Constants\ChatSignalConstants;

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

    public static function fromArray(array $data): static
    {
        $clientId = $data['clientUploadId'] ?? null;

        return new static(
            filename: is_string($data['filename'] ?? null) ? $data['filename'] : '',
            mimeType: is_string($data['mimeType'] ?? null) ? $data['mimeType'] : '',
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
        return $this->filename !== '' && $this->size > 0 && $this->mimeType !== '';
    }
}
