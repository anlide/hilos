<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Page\DTO;

use Demo\Chat\Constants\ChatSignalConstants;

/**
 * FileModerationDismissActionDTO - Clear rejected file moderation UI state.
 */
final class FileModerationDismissActionDTO extends ChatActionPayloadDTO
{
    public function getAction(): string
    {
        return ChatSignalConstants::FILE_MODERATION_DISMISS;
    }

    /**
     * @param array<string, mixed> $data Unused
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [];
    }
}
