<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\DTO\Action;

use Demo\WebSocketTest\Constants\ChatSignalConstants;

/**
 * RenameActionDTO - DTO for rename action payload
 *
 * Represents a user rename request.
 */
class RenameActionDTO extends ChatActionPayloadDTO
{
    /**
     * Constructor
     *
     * @param string $newName New username
     */
    public function __construct(
        public readonly string $newName,
    ) {
    }

    /**
     * Get action name
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::RENAME;
    }

    /**
     * Create from array
     *
     * @param array $data Payload data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        return new static(
            newName: is_string($data['newName'] ?? null) ? trim($data['newName']) : '',
        );
    }

    /**
     * Convert to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'newName' => $this->newName,
        ];
    }

    /**
     * Check if new name is valid (non-empty)
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->newName !== '';
    }
}
