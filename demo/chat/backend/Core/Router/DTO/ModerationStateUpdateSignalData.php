<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * ModerationStateUpdateSignalData - DTO for moderation state update signal (server → client).
 *
 * Sent only to the user's own connections. Private data, not broadcast.
 */
class ModerationStateUpdateSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates moderation state update signal data.
     *
     * @param ?string $moderationState Current moderation state (message text or null when cleared)
     */
    public function __construct(
        public readonly ?string $moderationState,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'moderationState' => $this->moderationState,
        ];
    }

    /**
     * Create DTO from array (for deserialization).
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $moderationState = $data['moderationState'] ?? null;
        return new self(
            moderationState: is_string($moderationState) ? $moderationState : null,
        );
    }
}
