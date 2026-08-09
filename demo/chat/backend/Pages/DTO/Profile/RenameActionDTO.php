<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Profile;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * RenameActionDTO - DTO for rename action payload.
 *
 * Represents a user rename request.
 */
final class RenameActionDTO extends ChatActionPayloadDTO
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
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            newName: trim(self::requireString($data, 'newName')),
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
