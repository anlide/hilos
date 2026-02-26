<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * ModerationStateUpdateSignalData - DTO for moderation state update signal (server → client)
 *
 * Sent only to the user's own connections. Private data, not broadcast.
 */
class ModerationStateUpdateSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        /** @var string|null Current moderation state (message text or null when cleared) */
        public readonly ?string $moderationState,
    ) {
    }

    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    public function toArray(): array
    {
        return [
            'moderationState' => $this->moderationState,
        ];
    }

    /**
     * Create DTO from array (for deserialization)
     *
     * @param array $data Source data
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
