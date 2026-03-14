<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Page\DTO;

use Demo\Chat\Constants\ChatSignalConstants;

/**
 * FileActionDTO - DTO for file action payload
 *
 * Represents a file upload/share request.
 */
class FileActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates file action DTO.
     *
     * @param string $filename Filename
     * @param string $mimeType MIME type
     * @param int $size File size in bytes
     */
    public function __construct(
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $size,
    ) {
    }

    /**
     * Get action name
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::FILE;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data (filename, mimeType, size)
     * @return static Instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            filename: is_string($data['filename'] ?? null) ? $data['filename'] : '',
            mimeType: is_string($data['mimeType'] ?? null) ? $data['mimeType'] : '',
            size: is_int($data['size'] ?? null) ? $data['size'] : 0,
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, string|int> Data with filename, mimeType, size keys
     */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'mimeType' => $this->mimeType,
            'size' => $this->size,
        ];
    }

    /**
     * Check if file data is valid
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->filename !== '' && $this->size > 0;
    }
}
