<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Page\DTO;

use Demo\Chat\Constants\ChatSignalConstants;

/**
 * Client action payload for deleting one uploaded attachment draft.
 */
final class AttachmentDraftDeleteActionDTO extends ChatActionPayloadDTO
{
    /**
     * @param string $draftId Draft id to delete
     */
    public function __construct(
        public readonly string $draftId,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::ATTACHMENT_DRAFT_DELETE;
    }

    /**
     * Create from wire payload.
     *
     * @param array<string, mixed> $data Payload data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $draftId = $data['draftId'] ?? '';

        return new static(is_string($draftId) ? $draftId : '');
    }

    /**
     * Convert to array for transport.
     *
     * @return array{draftId: string} Payload data
     */
    public function toArray(): array
    {
        return ['draftId' => $this->draftId];
    }

    /**
     * Check that the draft id is present.
     */
    public function isValid(): bool
    {
        return $this->draftId !== '';
    }
}
