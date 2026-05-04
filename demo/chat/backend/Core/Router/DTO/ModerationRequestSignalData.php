<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * ModerationRequestSignalData - DTO for moderation request signal (ChatAgent → ModeratorAgent).
 *
 * Routing is configured by signal name in ChatSignalRouter (moderate_request → moderator).
 */
final class ModerationRequestSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates moderation request signal data.
     *
     * @param string $requestId Moderation request id
     * @param string $acceptKey WebSocket accept key
     * @param int $userId User ID
     * @param string $message Original message text
     */
    public function __construct(
        public readonly string $requestId,
        public readonly string $acceptKey,
        public readonly int $userId,
        public readonly string $message,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, int|string> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'requestId' => $this->requestId,
            'acceptKey' => $this->acceptKey,
            'userId' => $this->userId,
            'message' => $this->message,
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
        return new self(
            requestId: (string)($data['requestId'] ?? ''),
            acceptKey: (string)($data['acceptKey'] ?? ''),
            userId: (int)($data['userId'] ?? 0),
            message: (string)($data['message'] ?? ''),
        );
    }
}
