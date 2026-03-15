<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * ModerationResultSignalData - DTO for moderation result signal (ModeratorAgent → ChatAgent).
 *
 * Routing is configured by signal name in ChatSignalRouter (moderation_result → chat).
 */
final class ModerationResultSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates moderation result signal data.
     *
     * @param string $acceptKey WebSocket accept key
     * @param int $userId User ID
     * @param string $message Message text
     * @param bool $allow Whether message is allowed
     * @param string $reason Moderation reason
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly int $userId,
        public readonly string $message,
        public readonly bool $allow,
        public readonly string $reason,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, int|string|bool> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'acceptKey' => $this->acceptKey,
            'userId' => $this->userId,
            'message' => $this->message,
            'allow' => $this->allow,
            'reason' => $this->reason,
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
            acceptKey: $data['acceptKey'] ?? '',
            userId: (int)($data['userId'] ?? 0),
            message: $data['message'] ?? '',
            allow: (bool)($data['allow'] ?? false),
            reason: $data['reason'] ?? '',
        );
    }
}
