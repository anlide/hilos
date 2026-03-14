<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Page\DTO;

use Demo\Chat\Constants\ChatSignalConstants;

/**
 * MessageActionDTO - DTO for message action payload.
 *
 * Represents a chat message sent by user.
 */
class MessageActionDTO extends ChatActionPayloadDTO
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
     * Supports both formats:
     * - {content: "..."}
     * - {data: {message: "..."}} (legacy format)
     *
     * @param array<string, mixed> $data Payload data (content or data.message)
     * @return static Message DTO instance
     */
    public static function fromArray(array $data): static
    {
        // Support both: {content:"..."} and {data:{message:"..."}} for compatibility
        $content = $data['content'] ?? null;

        if ($content === null && isset($data['data']) && is_array($data['data'])) {
            $content = $data['data']['message'] ?? null;
        }

        return new static(
            content: is_string($content) ? trim($content) : '',
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{content: string} Array with content key
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
        ];
    }

    /**
     * Check if message content is valid (non-empty).
     *
     * @return bool True if content is valid
     */
    public function isValid(): bool
    {
        return $this->content !== '';
    }
}
