<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * ModerationBotResultSignalData - DTO for bot message moderation result (ModeratorAgent → ChatAgent)
 */
class ModerationBotResultSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param int $botId Bot ID
     * @param string $message Message text
     * @param bool $allow Whether message is allowed
     * @param string $reason Moderation reason
     */
    public function __construct(
        public readonly int $botId,
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
            'botId' => $this->botId,
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
            botId: (int)($data['botId'] ?? 0),
            message: $data['message'] ?? '',
            allow: (bool)($data['allow'] ?? false),
            reason: $data['reason'] ?? '',
        );
    }
}
