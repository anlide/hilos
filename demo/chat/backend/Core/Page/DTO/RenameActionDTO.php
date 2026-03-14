<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Page\DTO;

use Demo\Chat\Constants\ChatSignalConstants;

/**
 * RenameActionDTO - DTO for rename action payload.
 *
 * Represents a user rename request.
 */
class RenameActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates rename action DTO.
     *
     * @param string $newName New username
     */
    public function __construct(
        public readonly string $newName,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::RENAME;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            newName: is_string($data['newName'] ?? null) ? trim($data['newName']) : '',
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, string> Data with newName key
     */
    public function toArray(): array
    {
        return [
            'newName' => $this->newName,
        ];
    }

    /**
     * Check if new name is valid (non-empty).
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->newName !== '';
    }
}
