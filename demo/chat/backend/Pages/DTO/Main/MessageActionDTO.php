<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * MessageActionDTO - DTO for message action payload.
 *
 * Represents a chat message submit. Uploaded attachment drafts are read from runtime state.
 */
final class MessageActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates message action DTO.
     *
     * @param string $content Message content
     */
    public function __construct(
        public readonly string $content,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::MESSAGE;
    }

    /**
     * Create from array.
     *
     * Supports both content and legacy data.message keys.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Message DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        $legacy = $data['data'] ?? null;
        if (($data['content'] ?? null) === null && is_array($legacy)) {
            return new static(content: trim(self::requireString($legacy, 'message')));
        }

        return new static(content: trim(self::requireString($data, 'content')));
    }

    /**
     * Convert to array for transport.
     *
     * @return array{content: string} Array with message content
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
        ];
    }

    /**
     * Check if the submit contains text.
     *
     * @return bool True if content is valid
     */
    public function isValid(): bool
    {
        return $this->content !== '';
    }
}
