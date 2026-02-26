<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * ModerationRequestSignalData - DTO for moderation request signal (ChatAgent → ModeratorAgent)
 *
 * Routing is configured by signal name in ChatSignalRouter (moderate_request → moderator).
 */
class ModerationRequestSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly string $acceptKey,
        public readonly int $userId,
        public readonly string $message,
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
            'acceptKey' => $this->acceptKey,
            'userId' => $this->userId,
            'message' => $this->message,
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
        return new self(
            acceptKey: $data['acceptKey'] ?? '',
            userId: (int)($data['userId'] ?? 0),
            message: $data['message'] ?? '',
        );
    }
}
