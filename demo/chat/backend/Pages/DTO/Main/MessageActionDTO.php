<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * MessageActionDTO - DTO for message action payload.
 *
 * Represents a chat message submit: its text and the files it carries, named by the client
 * ids of complete uploads of the connection's chat attachment target (HIL-144). Only the files
 * named here ride the message - an upload that completes while it is moderated does not.
 */
final class MessageActionDTO extends ChatActionPayloadDTO
{
    public const array SECRET_FIELDS = [];

    public const string content = 'content';
    public const string attachments = 'attachments';

    /**
     * Creates message action DTO.
     *
     * @param string $content Message content
     * @param list<string> $attachments Client ids of the complete uploads the message carries, in attach order
     */
    public function __construct(
        public readonly string $content,
        public readonly array $attachments = [],
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
     * Supports both content and legacy data.message keys; the attachments list may be absent,
     * which means a message without files.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Message DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or not a string, or the attachments are
     *     not a list of strings
     */
    public static function fromArray(array $data): static
    {
        $attachments = self::optionalStringList($data, self::attachments) ?? [];
        $legacy = $data['data'] ?? null;
        if (($data[self::content] ?? null) === null && is_array($legacy)) {
            return new static(content: trim(self::requireString($legacy, 'message')), attachments: $attachments);
        }

        return new static(content: trim(self::requireString($data, self::content)), attachments: $attachments);
    }

    /**
     * Convert to array for transport.
     *
     * @return array{content: string, attachments: list<string>} Message content and the attached upload ids
     */
    public function toArray(): array
    {
        return [
            self::content => $this->content,
            self::attachments => $this->attachments,
        ];
    }

    /**
     * Check if the submit carries text or at least one file.
     *
     * @return bool True when there is something to send
     */
    public function isValid(): bool
    {
        return $this->content !== '' || $this->attachments !== [];
    }
}
