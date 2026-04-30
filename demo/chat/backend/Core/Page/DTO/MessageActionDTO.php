<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Page\DTO;

use Demo\Chat\Constants\ChatSignalConstants;

/**
 * MessageActionDTO - DTO for message action payload.
 *
 * Represents a chat message submit with optional uploaded attachment drafts.
 */
final class MessageActionDTO extends ChatActionPayloadDTO
{
    /**
     * Creates message action DTO.
     *
     * @param string $content Message content
     * @param list<string> $attachmentDraftIds Uploaded attachment drafts to send with this message
     */
    public function __construct(
        public readonly string $content,
        public readonly array $attachmentDraftIds = [],
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
     */
    public static function fromArray(array $data): static
    {
        $content = $data['content'] ?? null;

        if ($content === null && isset($data['data']) && is_array($data['data'])) {
            $content = $data['data']['message'] ?? null;
        }

        $attachmentDraftIds = [];
        $seenDraftIds = [];
        $rawDraftIds = $data['attachmentDraftIds'] ?? [];
        if (is_array($rawDraftIds)) {
            foreach ($rawDraftIds as $draftId) {
                if (is_string($draftId) && $draftId !== '' && !isset($seenDraftIds[$draftId])) {
                    $attachmentDraftIds[] = $draftId;
                    $seenDraftIds[$draftId] = true;
                }
            }
        }

        return new static(
            content: is_string($content) ? trim($content) : '',
            attachmentDraftIds: $attachmentDraftIds,
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{content: string, attachmentDraftIds: list<string>} Array with content and attachment draft ids
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'attachmentDraftIds' => $this->attachmentDraftIds,
        ];
    }

    /**
     * Check if the submit contains text or at least one attachment draft.
     *
     * @return bool True if content is valid
     */
    public function isValid(): bool
    {
        return $this->content !== '' || $this->attachmentDraftIds !== [];
    }
}
