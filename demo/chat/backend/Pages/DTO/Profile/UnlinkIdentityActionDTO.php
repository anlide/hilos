<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\Profile;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;

/**
 * UnlinkIdentityActionDTO - DTO for the profile unlink-identity action payload.
 *
 * Carries the id of the linked login identity the user asked to remove; the
 * server re-checks ownership and the last-identity guard before deleting.
 */
final class UnlinkIdentityActionDTO extends ChatActionPayloadDTO
{
    public const string IDENTITY_ID = 'identityId';

    /**
     * Creates the unlink-identity action DTO.
     *
     * @param int $identityId Id of the identity to unlink (0 when absent/invalid)
     */
    public function __construct(
        public readonly int $identityId,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::UNLINK_IDENTITY;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static Instance
     */
    public static function fromArray(array $data): static
    {
        $identityId = $data[self::IDENTITY_ID] ?? null;

        return new static(
            identityId: is_numeric($identityId) ? (int)$identityId : 0,
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, int> Data with identityId key
     */
    public function toArray(): array
    {
        return [
            self::IDENTITY_ID => $this->identityId,
        ];
    }

    /**
     * Check if the payload is valid (a positive identity id).
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->identityId > 0;
    }
}
