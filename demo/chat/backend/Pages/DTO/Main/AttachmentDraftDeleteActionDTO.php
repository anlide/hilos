<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Main;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

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
     * @throws InvalidFormatException When a field the action needs is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, 'draftId'));
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
