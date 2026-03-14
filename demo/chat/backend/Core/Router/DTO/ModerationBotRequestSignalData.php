<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * ModerationBotRequestSignalData - DTO for bot message moderation request (BotAgent → ModeratorAgent).
 */
class ModerationBotRequestSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates bot moderation request signal data.
     *
     * @param int $botId Bot ID
     * @param string $message Message text to moderate
     */
    public function __construct(
        public readonly int $botId,
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
            'botId' => $this->botId,
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
            botId: (int)($data['botId'] ?? 0),
            message: $data['message'] ?? '',
        );
    }
}
