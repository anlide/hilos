<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\DTO\AdminUsers;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Pages\DTO\ChatActionPayloadDTO;
use Hilos\Constants\SignalPayloadConstants;

/**
 * ImpersonateStartActionDTO - payload for the admin users-table impersonate-start
 * action.
 *
 * Carries only the target user id; the acting admin session is taken from the
 * connection. Owned by {@see \Demo\Chat\Pages\AdminUsersPage} through its ACTIONS,
 * auto admin-gated by the page's ACCESS guard.
 */
final class ImpersonateStartActionDTO extends ChatActionPayloadDTO
{
    public const string targetUserId = 'targetUserId';

    /**
     * @param int $targetUserId User id the admin session will impersonate
     */
    public function __construct(
        public readonly int $targetUserId,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return ChatSignalConstants::IMPERSONATE_START;
    }

    /**
     * Create from array, unwrapping the optional FIELD_DATA envelope.
     *
     * @param array<string, mixed> $data Raw payload (may contain FIELD_DATA wrapper)
     * @return static Impersonate-start DTO instance
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            $inner = [];
        }

        return new static(
            targetUserId: (int)($inner[self::targetUserId] ?? 0),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array<string, mixed> Payload with the target user id
     */
    public function toArray(): array
    {
        return [
            self::targetUserId => $this->targetUserId,
        ];
    }
}
